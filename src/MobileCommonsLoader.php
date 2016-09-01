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

  function profilesEachBatch($page = 1, $limit = 0) {
    // Exit on out of bounds.
    if ($limit != 0 && $page > $limit) {
      return false;
    }
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

    if ($this->sleep > 0) {
      sleep($this->sleep);
    }

    $profiles = $response->profiles;
    if ($profiles) {
      return $profiles;
    }
    return false;
  }

}
