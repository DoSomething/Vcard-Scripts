<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-f, --from <int> Last element to load, default 0
-t, --to <int> First element to load
-h, --help Show this help
");

$args = (array) $opts;
$argFrom = !empty($args['from']) ? (int) $args['from'] : 0;
$argTo   = !empty($args['to'])   ? (int) $args['to']   : 0; // 3275175

// --- Imports ---
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zend\ProgressBar\ProgressBar;

// --- DS Imports ---

// --- Logger ---
$logNamePrefix =  $argFrom
 . '-' . $argTo
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
echo 'Loading data from Redis.' . PHP_EOL;
// Loading all keys from Redis for the pagination using KEYS command.
// Turned out to be 7 times faster, than recommended SCAN.
$keys = $redisRead->keys(REDIS_KEY . ':*');

echo 'Sorting Redis keys.' . PHP_EOL;
sort($keys);

// --- Progress ---
$progressData = (object) [
  'current' => $argFrom,
  'max' => $argTo ? $argTo : count($keys) - 1,
];
if ($progressData->current > $progressData->max) {
  exit('To must be greater than from.' . PHP_EOL);
}
$progress = new ProgressBar(
  $progressAdapter,
  $progressData->current,
  $progressData->max
);

// --- Get data ---
// Retry when we get no keys back.
$redisRead->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
for (;$progressData->current <= $progressData->max; $progressData->current++) {
  $key = $keys[$progressData->current];

  // Initiate Redis transcation.
  $ret = $redis->multi();

  // Monitor progress.
  $progress->update(
    $progressData->current,
    $progressData->current . '/' . $progressData->max
  );

  // Process batch.
  try {

    // Load user from redis.
    $mocoRedisUser = $redisRead->hGetAll($key);

    // Skip unprocessed users.
    if (empty($mocoRedisUser['step2_status'])) {
      $logMessage = '{current} of {max}, Redis key {key}.'
        . ' Skipping profile #{phone}, MoCo id {id}:'
        . ' it hasn\'t been processed yet. Please get back to it';

      $log->warning($logMessage, [
        'current' => $progressData->current,
        'max'     => $progressData->max,
        'key'     => $key,
        'phone'   => $mocoRedisUser['phone_number'],
        'id'      => $mocoRedisUser['id'],
      ]);
      $ret->discard();
      continue;
    }

    // Skip processed users.
    $skipProcessed = !empty($mocoRedisUser['step3_status'])
      && $mocoRedisUser['step3_status'] === 'updated';

    if ($skipProcessed) {
      $logMessage = '{current} of {max}, Redis key {key}.'
        . ' Skipping profile #{phone}, MoCo id {id}:'
        . ' it\'s already processed';

      $log->info($logMessage, [
        'current' => $progressData->current,
        'max'     => $progressData->max,
        'key'     => $key,
        'phone'   => $mocoRedisUser['phone_number'],
        'id'      => $mocoRedisUser['id'],
      ]);
      $ret->discard();
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

    $logMessage = '{current} of {max}, Redis key {key}.'
      . ' Saving profile #{phone}, MoCo id {id}, fields: {fields}';

    $log->debug($logMessage, [
      'current' => $progressData->current,
      'max'     => $progressData->max,
      'key'     => $key,
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
