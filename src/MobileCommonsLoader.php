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
  public $testPhones = [];

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
    // Test phones param.
    if (!empty($this->testPhones)) {
      $params['phone_number'] = implode(',', $this->testPhones);
    }

    $response = $this->moco->profiles($params);
    if ($response->error->count()) {
      $this->log->error(
        'Error during loading data from MoCo: id: {id}, message: {message}',
        [
          'id'      => $response->error['id'],
          'message' => $response->error['message'],
        ]
      );
      throw new \Exception($response->error['message']);
    }

    if (!count($response->profiles->children())) {
      $this->log->debug(
        'No more profiles to load, stopped on page {page}',
        ['page' => $page]
      );
      return false;
    }

    if ($this->sleep > 0) {
      sleep($this->sleep);
    }

    return $response->profiles;
  }

}
