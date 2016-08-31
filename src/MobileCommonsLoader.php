<?php

namespace DoSomething\Vcard;

use Psr\Log\LoggerInterface;

/**
*
*/
class MobileCommonsLoader
{

  private $moco = false;
  private $log = false;
  public $batchSize = 100;
  public $sleep = 0;

  function __construct(Array $config, LoggerInterface $logger) {
    $this->moco = new \MobileCommons($config);
    $this->log = $logger;
  }

  function profilesEachBatch($callback, $page = 1, $limit = 0) {
    $this->log->debug(
      'Loading profiles from MoCo, batch size {size}, page {page}',
      [
        'size' => $this->batchSize,
        'page' => $page,
      ]
    );

    $params = [
      'limit' => $this->batchSize,
      'page' => $page,
    ];

    $response = $this->moco->profiles($params);

    if (empty($response->profiles)) {
      $this->log->debug(
        'No more profiles to load, stopped on page {page}',
        [
          'page' => $page,
        ]
      );
      return ($page > 1);
    }

    $profiles = $response->profiles;
    $numReturned = (int) $profiles->profile->count();

    $callback($profiles);

    if ($this->sleep > 0) {
      sleep($this->sleep);
    }

    if ($limit == 0 || $page < $limit) {
      return $this->profilesEachBatch($callback, $page + 1, $limit);
    }
  }

}
