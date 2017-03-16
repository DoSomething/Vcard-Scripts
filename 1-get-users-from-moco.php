<?php

// --- Config ---
require 'config.php';

// ---  Defines ---
// Current count: https://secure.mcommons.com/profiles?count_only=true
define('CURRENT_MOCO_PROFILES_COUNT', 5759512);

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
// var_dump($args);
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
// use Carbon\Carbon;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
// use Zend\ProgressBar\ProgressBar;

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
// $progress = new ProgressBar(
//   $progressAdapter,
//   $progressData->current,
//   $progressData->max
// );

$parsed_users = [];
$users_collection = $mongo_db->moco->users;

// --- Get data ---
while ($profiles = $moco->profilesEachBatch($progressData->page++, $argLastPage)) {
  // var_dump(
  //   round(memory_get_usage()/1048576,2)." megabytes",
  //   round(memory_get_usage(true)/1048576,2)." megabytes"
  // );

  // Initiate Redis transcation.
  // $ret = $redis->multi();
  try {
    foreach ($profiles->profile as $profile) {
      $attributes = $profile->attributes();

      /*
        This is an aggressively conservative check here. All profiles SHOULD have an id attribute DUH,
        but I can't guarantee MoCo will keep their schema intact over time
      */
      if (isset($attributes["id"])) {
        $id = (string) $attributes["id"];
      }else {
        throw new Exception('Does not have profile ID');
      }

      /*
        By adding an _id child, we are forcing MongoDB to use this property as the internal,
        unique _id in the collection when inserting this profile.
        This is necessary to avoid duplication of MoCo profiles in the back up,
        while keeping lean and efficient without extra validation logic.
        If a collition is detected when bulk inseting, mongo will not insert a duplicate.
      */
      $profile->addChild("_id", "$id");
      array_push($parsed_users, $profile);
    }
    // $ret->exec();

    /*
      We do a bulk insert of all profiles.
      Not requiring order allows the bulk insert to skip failed insertions
      but continue to insert as many as possible.
    */
    $insertion = $users_collection->insertMany($parsed_users, [ "ordered" => false ]);
    printf("Inserted %d document(s)\n", $insertion->getInsertedCount());

  /*
    Here we catch all failed writes to the DB.
    The ids and index of the failed profiles is logged here.
  */
  } catch (MongoDB\Driver\Exception\BulkWriteException $e) {

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


    $writeResult = $e->getWriteResult();

    if ($writeErrors = $writeResult->getWriteErrors()) {

      function parse($error) {
        $message = $error->getMessage();
        $index = $error->getIndex();
        return "MESSAGE: $message. INDEX: $index";
      }

      $errors_to_be_logged = array_map("parse", $writeErrors);

      print(implode(PHP_EOL, $errors_to_be_logged));

      // Log write exception. Most likely triggered by am _id collition
      $log->debug("{error}", ["error" => implode(PHP_EOL, $errors_to_be_logged)]);
    }
    // throw $e;

  } catch (Exception $e) {


    /*
      TODO: This is a horrible copy/paste non-DRY way of using this logger,
      but as it is a script that it will rarely be used (sure), I feel less horrible
      about it,
    */

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

    // $ret->discard();
    $log->debug("{error}", ["error" => $e->getMessage()]);
    throw $e;
  }

  // Force garbage collector.
  gc_collect_cycles();
}

// Set 100% when estimated $progressMax turned out to be incorrect.
// if ($progressData->current != $progressData->max) {
//   $progress->update(
//     $progressData->max,
//     $progressData->max . '/' . $progressData->max
//   );
// }
// $progress->finish();

// $redisRead->close();
