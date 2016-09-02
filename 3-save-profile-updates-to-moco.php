<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-i, --iterator <int> Scan iterator value of last successfully saved batch. Works only with unchanged hashes
-l, --last <int> A number of last successfully saved element. Works only with unchanged hashes
-h, --help Show this help
");

$args = (array) $opts;
$iterator = !empty($args['iterator']) ? (int) $args['iterator'] : NULL;

// --- Imports ---
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zend\ProgressBar\ProgressBar;

// --- DS Imports ---

// --- Logger ---
$logNamePrefix = REDIS_SCAN_COUNT
 . '-' . ($iterator ?: 0)
 . '-save-profiles-to-moco-';

// File.
$mainLogName = __DIR__ . '/log/' . $logNamePrefix . 'output.log';
$logfile = fopen($mainLogName, "w");
$logFileStream = new StreamHandler($logfile);
$logFileStream->setFormatter(new LineFormatter($output . "\n", $dateFormat));
$log->pushHandler($logFileStream);
// Warning File.
$logfile = fopen(__DIR__ . '/log/' . $logNamePrefix . 'warning.log', "w");
$logFileStream = new StreamHandler($logfile, Logger::WARNING);
$logFileStream->setFormatter(new LineFormatter($output . "\n", $dateFormat));
$log->pushHandler($logFileStream);

// Display main log filename.
echo 'Logging to ' . $mainLogName . PHP_EOL;

// --- Progress ---
$progressData = (object) [
  'current' => !empty($args['last']) ? (int) $args['last'] : 0,
  'max' => $redisRead->dbSize(),
];
$progress = new ProgressBar(
  $progressAdapter,
  $progressData->current,
  $progressData->max
);

// --- Get data ---
// Retry when we get no keys back.
$redisRead->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
while($keysBatch = $redisRead->scan($iterator, REDIS_KEY . ':*', REDIS_SCAN_COUNT)) {

  // Initiate Redis transcation.
  $ret = $redis->multi();

  // Process batch.
  try {
    foreach($keysBatch as $key) {
      // Monitor progress.
      $progress->update(
        ++$progressData->current,
        $progressData->current . '/' . $progressData->max
      );

      // Load user from redis.
      $mocoRedisUser = $redisRead->hGetAll($key);

      // Skip unprocessed users.
      if (empty($mocoRedisUser['step2_status'])) {
        $logMessage = '{current} of {max}, iterator {it}: '
          . 'Skipping profile #{phone}, MoCo id {id}: '
          . 'it hasn\'t been processed yet. Please get back to it';

        $log->warning($logMessage, [
          'current' => $progressData->current,
          'max'     => $progressData->max,
          'it'      => $iterator,
          'phone'   => $mocoRedisUser['phone_number'],
          'id'      => $mocoRedisUser['id'],
        ]);
        continue;
      }

      $mocoProfileUpdate = [
        'phone_number'       => $mocoRedisUser['phone_number'],
        // 'vcard_share_url_id' => $mocoRedisUser['vcard_share_url_id'],
        'vcard_share_url_full' => $mocoRedisUser['vcard_share_url_full'],
      ];
      if (!empty($mocoRedisUser['northstar_id'])) {
        $mocoProfileUpdate['northstar_id']  = $mocoRedisUser['northstar_id'];
        $mocoProfileUpdate['birthdate']     = $mocoRedisUser['birthdate'];
        $mocoProfileUpdate['Date of Birth'] = $mocoRedisUser['birthdate'];
      }

      $logMessage = '{current} of {max}, iterator {it}: '
        . 'Saving profile #{phone}, MoCo id {id}, fields: {fields}';

      $log->debug($logMessage, [
        'current' => $progressData->current,
        'max'     => $progressData->max,
        'it'      => $iterator,
        'phone'   => $mocoRedisUser['phone_number'],
        'id'      => $mocoRedisUser['id'],
        'fields'  => json_encode($mocoProfileUpdate),
      ]);

      $result = $moco->updateProfile($mocoProfileUpdate);
      if ($result) {
        $log->debug('Succesfully saved profile #{phone}, MoCo id {id}', [
          'phone'   => $mocoRedisUser['phone_number'],
          'id'      => $mocoRedisUser['id'],
        ]);
        $ret->hSet($key, 'step3_status', 'updated');
      } else {
        $ret->hSet($key, 'step3_status', 'failed');
      }
    }

    // Batch processed.
    $ret->exec();
  } catch (Exception $e) {
    $ret->discard();
    throw $e;
  }

  // Force garbage collector.
  gc_collect_cycles();
}

// Set 100% when estimated $progressMax turned out to be incorrect.
if ($progressData->current != $progressData->max) {
  $progress->update(
    $progressData->max,
    $progressData->max . '/' . $progressData->max
  );
}
$progress->finish();

$redisRead->close();
