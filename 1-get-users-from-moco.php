<?php

// --- Config ---
require 'config.php';

// ---  Options ---
$opts = CLIOpts\CLIOpts::run("
{self}
-p, --page <page> MoCo profiles start page, defaults to 1
-l, --last <page> MoCo profiles last page, defaults to 0
-b, --batch <1-1000> MoCo profiles batch size, defaults to 100
-h, --help Show this help
");

$args = (array) $opts;
$arg_page = !empty($args['page']) ? $args['page'] : 1;
$arg_last = !empty($args['last']) ? $args['last'] : 0;

if (!empty($args['batch']) && $args['batch'] => 1 && $args['batch'] <= 1000) {
  $moco->batchSize = (int) $args['batch'];
}

// --- Get data ---
$moco->profilesEachBatch(function (SimpleXMLElement $profiles) use ($redis, $log) {
  $payloads = [];

  $ret = $redis->multi();
  try {
    foreach ($profiles->profile as $profile) {
      $user = [
        'id'           => (string) $profile['id'],
        'phone_number' => (string) $profile->phone_number,
        'email'        => (string) $profile->email,
      ];
      // $payload = json_encode($user);
      $ret->hMSet(REDIS_KEY . ":" . $profile['id'], $user);
    }
    $ret->exec();
  } catch (Exception $e) {
    $ret->discard();
    throw $e;
  }

}, $arg_page, $arg_last);

$redis->close();
