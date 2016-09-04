<?php

namespace DoSomething\Vcard;

use DoSomething\Northstar\NorthstarClient;
use DoSomething\Northstar\Resources\NorthstarUser;
use Psr\Log\LoggerInterface;

/**
*
*/
class NorthstarLoader
{

  const RETRY_MAX = 10;
  const RETRY_PAUSE = 20;

  private $client = false;
  private $log = false;

  public $batchSize = 100;
  public $sleep = 0;

  function __construct(NorthstarClient $client, LoggerInterface $logger) {
    $this->client = $client;
    $this->log = $logger;
  }

  function loadFromMocoData(Array $mocoRedisUser) {
    // Cleanup phone number.
    // @see https://github.com/DoSomething/northstar/blob/dev/app/Models/User.php#L185
    $phoneNumber = preg_replace('/[^0-9]/', '', $mocoRedisUser['phone_number']);

    // First, load mobile number shortened version.
    // Strip US +1 code from the beginning.
    if (strlen($phoneNumber) === 11 && $phoneNumber[0] === '1') {
      $shortenedPhoneNumber = substr($phoneNumber, 1);
      $this->log->debug('Loading by shortened phone #{phone}', [
        'phone'   => $shortenedPhoneNumber,
      ]);
      $northstarUser = $this->getUser('mobile', $shortenedPhoneNumber);
      if ($northstarUser) {
        return $northstarUser;
      }
    }

    // Second, load by full phone number.
    $this->log->debug('Loading by full phone #{phone}', [
      'phone'   => $phoneNumber,
    ]);
    $northstarUser = $this->getUser('mobile', $phoneNumber);
    if ($northstarUser) {
      return $northstarUser;
    }

    // Third, try to load by email.
    if (empty($mocoRedisUser['email'])) {
      $this->log->warning('Can\'t load user #{phone}', [
        'phone'   => $phoneNumber,
      ]);
      return false;
    }

    $this->log->debug('Loading by email {email}', [
      'email' => $mocoRedisUser['email'],
    ]);
    $northstarUser = $this->getUser('mobile', $phoneNumber);
    if ($northstarUser) {
      return $northstarUser;
    }
    $this->log->warning('Can\'t load user #{phone}', [
      'phone'   => $phoneNumber,
    ]);
    return false;
  }

  private function getUser($type, $id, $retryCount = 0) {
    try {
      return $this->client->getUser($type, $id);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
      $retryCount++;
      if ($retryCount <= self::RETRY_MAX) {
        $logMessage = 'Retry {count} of {max}.'
        . ' Caught Guzzle connection error:'
        . ' {error}. Sleeping for {pause} seconds';
        $this->log->warning($logMessage,
          [
            'count' => $retryCount,
            'max'   => self::RETRY_MAX,
            'error' => $e->getMessage(),
            'pause' => self::RETRY_PAUSE,
          ]
        );
        sleep(self::RETRY_PAUSE);
        return $this->getUser($type, $id, $retryCount);
      } else {
        throw new \Exception('Northstar loader failed after max retries: ' . $e->getMessage());
        return false;
      }
    }
  }

}
