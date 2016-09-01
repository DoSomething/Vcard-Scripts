<?php

// --- Config ---
require 'config.php';

// ---  Defines ---
// Current count: https://secure.mcommons.com/profiles?count_only=true
define('CURRENT_MOCO_PROFILES_COUNT', 5221749);

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-p, --page <int> MoCo profiles start page, defaults to 1
-l, --last <int> MoCo profiles last page, defaults to 0
-b, --batch <1-1000> MoCo profiles batch size, defaults to 100
-s, --sleep <0-60> Sleep between MoCo calls, defaults to 0
--test-phones <15551111111,15551111112> Comma separated phone numbers. Intended for tests
-h, --help Show this help
");

$args = (array) $opts;
$argFirstPage = !empty($args['page']) ? (int) $args['page'] : 1;
$argLastPage = !empty($args['last']) ? (int) $args['last'] : 0;

if (!empty($args['batch']) && $args['batch'] >= 1 && $args['batch'] <= 1000) {
  $moco->batchSize = (int) $args['batch'];
}

if (!empty($args['sleep']) && $args['sleep'] >= 1 && $args['sleep'] <= 60) {
  $moco->sleep = (int) $args['sleep'];
}

// Override data if test phones are provided.
if (!empty($args['test-phones'])) {
  $matches = null;
  preg_match_all('/1[0-9]{10}/', $args['test-phones'], $matches);
  $argPhones = reset($matches);
  if (!empty($argPhones)) {
    // Phones found.
    $argFirstPage  = 1;
    $argLastPage  = count($argPhones);
    $moco->batchSize = 1;
    $moco->testPhones = $argPhones;
  }
}

// Save batch size to a convenience variable.
$argBatchSize = $moco->batchSize;

// --- Imports ---
use Carbon\Carbon;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zend\ProgressBar\ProgressBar;

// --- Logger ---
$logNamePrefix = $argBatchSize
 . '-' . $argFirstPage
 . '-' . $argLastPage
 . '-get-users-from-moco-';

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
// First element.
if ($argFirstPage > 1) {
  // n1 = (p - 1) * b +1.
  // Example: -b 3 -l 7 -p: n1 = (3 - 1) * 3 + 1 = 7. Seq: 7, 8, 9 .. 19, 20, 21.
  $firstElement = (($argFirstPage - 1) * $argBatchSize + 1);
} else {
  // When it's first page always start with 1st element.
  $firstElement = 1;
}

// Last element.
if ($argLastPage === 0) {
  // Exception, estimate number of elements on MoCo.
  $lastElement = CURRENT_MOCO_PROFILES_COUNT;
} else {
  $lastElement = $argBatchSize * $argLastPage;
}

// Prepare pagination registry.
$progressData = (object) [
  'page'    => $argFirstPage,
  'current' => $firstElement,
  'max'     => $lastElement,
];

// Setup progress bar.
$progress = new ProgressBar(
  $progressAdapter,
  $progressData->current,
  $progressData->max
);

// --- Get data ---
while ($profiles = $moco->profilesEachBatch($progressData->page++, $argLastPage)) {
  // var_dump(
  //   round(memory_get_usage()/1048576,2)." megabytes",
  //   round(memory_get_usage(true)/1048576,2)." megabytes"
  // );

  // Initiate Redis transcation.
  $ret = $redis->multi();
  try {
    foreach ($profiles->profile as $profile) {
      // Get status.
      // @see: https://mobilecommons.zendesk.com/hc/en-us/articles/202052284-Profiles
      $statusTokens = [
          'Undeliverable' => 'undeliverable', // Phone number can't receive texts
          'Hard bounce' => 'undeliverable', // Invalid mobile number
          'No Subscriptions' => 'opted_out', // User is not opted in to any MC campaigns
          'Texted a STOP word' => 'opted_out', // User opted-out by texting STOP
          'Active Subscriber' => 'active',
      ];

      $phoneNumber = (string) $profile->phone_number;

      // Map to normalized status keywords, or 'unknown' on unknown status
      $mocoStatus = (string) $profile->status;
      $status = isset($statusTokens[$mocoStatus]) ? $statusTokens[$mocoStatus] : 'unknown';

      // Skip undeliverables.
      if ($status === 'undeliverable') {
        $log->debug('{current} of {max}: Skipping #{phone} as undeliverable', [
          'current' => $progressData->current,
          'max'     => $progressData->max,
          'phone'   => $phoneNumber
        ]);

        // Monitor progress.
        $progress->update(
          ++$progressData->current,
          $progressData->current . '/' . $progressData->max
        );
        continue;
      }

      // Process.
      $log->debug('{current} of {max}: Processing #{phone}', [
        'current' => $progressData->current,
        'max'     => $progressData->max,
        'phone'   => $phoneNumber
      ]);

      // Monitor progress.
      $progress->update(
        ++$progressData->current,
        $progressData->current . '/' . $progressData->max
      );

      $createdAt = (string) $profile->created_at;
      $user = [
        'id'           => (string) $profile['id'],
        'phone_number' => $phoneNumber,
        'email'        => (string) $profile->email,
        'status'       => $status,
        'created_at'   => Carbon::parse($createdAt)->format('Y-m-d'),
      ];
      $ret->hMSet(REDIS_KEY . ":" . $profile['id'], $user);
    }
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
