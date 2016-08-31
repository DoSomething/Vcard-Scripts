<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-i, --iterator <int> Last iterator value
-l, --last <int> Last current count
");

$args = (array) $opts;
$iterator = !empty($args['iterator']) ? (int) $args['iterator'] : NULL;

// --- Imports ---
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Logger ---
$logNamePrefix = REDIS_SCAN_COUNT
 . '-' . ($iterator ?: 0)
 . '-generate-links-';

// File.
$logfile = fopen(__DIR__ . '/log/' . $logNamePrefix . 'output.log', "w");
$logFileStream = new StreamHandler($logfile);
$logFileStream->setFormatter(new LineFormatter($output . "\n", $dateFormat));
$log->pushHandler($logFileStream);
// Warning File.
$logfile = fopen(__DIR__ . '/log/' . $logNamePrefix . 'warning.log', "w");
$logFileStream = new StreamHandler($logfile, Logger::WARNING);
$logFileStream->setFormatter(new LineFormatter($output . "\n", $dateFormat));
$log->pushHandler($logFileStream);

// --- Progress ---
$progressCurrent = !empty($args['last']) ? (int) $args['last'] : 0;
$progressMax = $redis->dbSize();
$progress = new \ProgressBar\Manager(0, $progressMax);
$progress->update($progressCurrent);

// --- Get data ---
// Retry when we get no keys back.
$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
while($keysBatch = $redis->scan($iterator, REDIS_KEY . ':*', REDIS_SCAN_COUNT)) {
  foreach($keysBatch as $key) {
    // Monitor progress.
    try {
      $progress->advance();
    } catch (InvalidArgumentException $e) {
      // Ignore current > max error.
    } catch (Exception $e) {
      // Log other errors.
      $log->error($e);
    }

    $mocoUser = $redis->hGetAll($key);

    $log->debug('{current} of {max}, iterator {it}: Processing #{phone}', [
      'current' => $progress->getRegistry()->getValue('current'),
      'max'     => $progress->getRegistry()->getValue('max'),
      'it'      => $iterator,
      'phone'   => $mocoUser['phone_number'],
    ]);

  }
  // Process new batch.
}

// Set 100% when estimated $progressMax turned out to be incorrect.
if ($progress->getRegistry()->getValue('current') < $progressMax) {
  $progress->update($progressMax);
}

$redis->close();
