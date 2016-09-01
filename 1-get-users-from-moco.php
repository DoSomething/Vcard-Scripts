<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-p, --page <page> MoCo profiles start page, defaults to 1
-l, --last <page> MoCo profiles last page, defaults to 0
-b, --batch <1-1000> MoCo profiles batch size, defaults to 100
-s, --sleep <0-60> Sleep between MoCo calls, defaults to 0
-h, --help Show this help
");

$args = (array) $opts;
$argPage = !empty($args['page']) ? $args['page'] : 1;
$argLast = !empty($args['last']) ? $args['last'] : 0;

if (!empty($args['batch']) && $args['batch'] >= 1 && $args['batch'] <= 1000) {
  $moco->batchSize = (int) $args['batch'];
}

if (!empty($args['sleep']) && $args['sleep'] >= 1 && $args['sleep'] <= 60) {
  $moco->sleep = (int) $args['sleep'];
}

// --- Imports ---
use Carbon\Carbon;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zend\ProgressBar\ProgressBar;

// --- Logger ---
$logNamePrefix = $moco->batchSize
 . '-' . $argPage
 . '-' . $argLast
 . '-get-users-from-moco-';

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
// Current count: https://secure.mcommons.com/profiles?count_only=true
define('CURRENT_MOCO_PROFILES_COUNT', 5221749);
$progressData = (object) [
  'current' => ($argPage > 1) ? $moco->batchSize * $argPage : 0,
  'max' => ($argLast > 0) ? $moco->batchSize * $argLast : CURRENT_MOCO_PROFILES_COUNT,
];
$progress = new ProgressBar(
  $progressAdapter,
  $progressData->current,
  $progressData->max
);


// --- Get data ---
$moco->profilesEachBatch(function (SimpleXMLElement $profiles)
                                  use ($redis, $log, $progress, $progressData) {

  // Initiate Redis transcation.
  $ret = $redis->multi();
  try {
    foreach ($profiles->profile as $profile) {
      // Monitor progress.
      $progress->update(
        ++$progressData->current,
        $progressData->current . '/' . $progressData->max
      );

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
        continue;
      }

      // Process.
      $log->debug('{current} of {max}: Processing #{phone}', [
        'current' => $progressData->current,
        'max'     => $progressData->max,
        'phone'   => $phoneNumber
      ]);

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

}, $argPage, $argLast);

// Set 100% when estimated $progressMax turned out to be incorrect.
if ($progressData->current < $progressData->max) {
  $progress->update(
    $progressData->max,
    $progressData->max . '/' . $progressData->max
  );
}
$progress->finish();

$redisRead->close();
