<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-f, --from <int> Last element to load, default 0
-t, --to <int> First element to load
-u, --url <url> Link base url. Defaults to https://www.dosomething.org/us/campaigns/lose-your-v-card
-h, --help Show this help
");

$args = (array) $opts;
$argFrom = !empty($args['from']) ? (int) $args['from'] : 0;
$argTo   = !empty($args['to'])   ? (int) $args['to']   : 0; // 3275175
$baseURL = !empty($args['url']) ? $args['url'] : 'https://www.dosomething.org/us/campaigns/lose-your-v-card';

// --- Imports ---
use Carbon\Carbon;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zend\ProgressBar\ProgressBar;

// --- DS Imports ---
use DoSomething\Vcard\NorthstarLoader;

// --- Logger ---
$logNamePrefix = $argFrom
 . '-' . $argTo
 . '-generate-links-';

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

$northstarLoader = new NorthstarLoader($northstar, $log);
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
    $log->debug('{current} of {max}, Redis key {key}: Loading user #{phone}, MoCo id {id}', [
      'current' => $progressData->current,
      'max'     => $progressData->max,
      'key'     => $key,
      'phone'   => $mocoRedisUser['phone_number'],
      'id'      => $mocoRedisUser['id'],
    ]);

    // Match user on Northstar
    $northstarUser = $northstarLoader->loadFromMocoData($mocoRedisUser);
    if ($northstarUser) {
      $log->info('Matched Northstar user id {northstar_id} with MoCo id {id}', [
        'northstar_id' => $northstarUser->id,
        'id' => $mocoRedisUser['id'],
      ]);
      $updateValues = [
        'northstar_id' => $northstarUser->id,
        'birthdate' => Carbon::parse((string) $northstarUser->birthdate)->format('Y-m-d'),
      ];
      $ret->hMSet($key, $updateValues);
      $link_source = 'user/' . $northstarUser->id;
    } else {
      $link_source = 'mcuser/' . $mocoRedisUser['id'];
    }

    $link = $baseURL;
    $link .= '?source=';
    $link .= $link_source;

    $noMocoLink = empty($mocoRedisUser['vcard_share_url_full']);
    $linkUpdated = !$noMocoLink && $mocoRedisUser['vcard_share_url_full'] !== $link;
    if ($noMocoLink || $linkUpdated) {
      if ($linkUpdated) {
        $log->debug('Replacing old link {old_link} with new link {new_link}', [
          'old_link'   => $mocoRedisUser['vcard_share_url_full'],
          'new_link'   => $link,
        ]);
      }
      $ret->hSet($key, 'vcard_share_url_full', $link);
    }


    // if (empty($mocoRedisUser['vcard_share_url_id']) || $linkUpdated) {
    //   $log->debug('Generating bitly link for {link}', [
    //     'link'   => $link,
    //   ]);
    //   $bitlyLink = $bitly->Shorten(["longUrl" => $link]);
    //   if (!empty($bitlyLink['url'])) {
    //     $shortenedLink = $bitlyLink['url'];
    //     $log->debug('Shortened link for {link} is {shortened_link}', [
    //       'link'           => $link,
    //       'shortened_link' => $shortenedLink,
    //     ]);
    //     $ret->hSet($key, 'vcard_share_url_id', $shortenedLink);
    //   }
    // }

    $ret->hSet($key, 'step2_status', 1);

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
