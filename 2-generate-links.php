<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-i, --iterator <int> Last iterator value
-l, --last <int> Last current count
-u, --url <url> Link base url. Defaults to https://www.dosomething.org/us/campaigns/lose-your-v-card
");

$args = (array) $opts;
$baseURL = !empty($args['url']) ? $args['url'] : 'https://www.dosomething.org/us/campaigns/lose-your-v-card';
$iterator = !empty($args['iterator']) ? (int) $args['iterator'] : NULL;

// --- Imports ---
use Carbon\Carbon;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// --- DS Imports ---
use DoSomething\Vcard\NorthstarLoader;

// --- Logger ---
$logNamePrefix = REDIS_SCAN_COUNT
 . '-' . ($iterator ?: 0)
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

// Show log.
echo 'Logging to ' . $mainLogName . PHP_EOL;

// --- Progress ---
$progressCurrent = !empty($args['last']) ? (int) $args['last'] : 0;
$progressMax = $redisRead->dbSize();
$progress = new \ProgressBar\Manager(0, $progressMax);
$progress->update($progressCurrent);

// --- Get data ---
// Retry when we get no keys back.
$northstarLoader = new NorthstarLoader($northstar, $log);
$redisRead->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
while($keysBatch = $redisRead->scan($iterator, REDIS_KEY . ':*', REDIS_SCAN_COUNT)) {

  // Redis transcation.
  $ret = $redis->multi();

  // Process batch.
  try {
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

      // Load user from redis.
      $mocoRedisUser = $redisRead->hGetAll($key);
      $log->debug('{current} of {max}, iterator {it}: Loading user #{phone}, MoCo id {id}', [
        'current' => $progress->getRegistry()->getValue('current'),
        'max'     => $progress->getRegistry()->getValue('max'),
        'it'      => $iterator,
        'phone'   => $mocoRedisUser['phone_number'],
        'id'      => $mocoRedisUser['id'],
      ]);

      // Match user on Northstar
      $northstarUser = $northstarLoader->loadFromMocoData($mocoRedisUser);
      if ($northstarUser) {
        $log->debug('Matched Northstar user id {northstar_id} with MoCo id {id}', [
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


      if (empty($mocoRedisUser['vcard_share_url_id']) || $linkUpdated) {
        $log->debug('Generating bitly link for {link}', [
          'link'   => $link,
        ]);
        $bitlyLink = $bitly->Shorten(["longUrl" => $link]);
        if (!empty($bitlyLink['url'])) {
          $shortenedLink = $bitlyLink['url'];
          $log->debug('Shortened link for {link} is {shortened_link}', [
            'link'           => $link,
            'shortened_link' => $shortenedLink,
          ]);
          $ret->hSet($key, 'vcard_share_url_id', $shortenedLink);
        }
      }
    }

    // Batch processed.
    $ret->exec();
  } catch (Exception $e) {
    $ret->discard();
    throw $e;
  }


}

// Set 100% when estimated $progressMax turned out to be incorrect.
if ($progress->getRegistry()->getValue('current') < $progressMax) {
  $progress->update($progressMax);
}

$redisRead->close();
