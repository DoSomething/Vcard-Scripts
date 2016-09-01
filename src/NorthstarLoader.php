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
    }
    $this->log->debug('Loading by shortened phone #{phone}', [
      'phone'   => $shortenedPhoneNumber,
    ]);
    $northstarUser = $this->client->getUser('mobile', $shortenedPhoneNumber);
    if ($northstarUser) {
      return $northstarUser;
    }

    // Second, load by full phone number.
    $this->log->debug('Loading by full phone #{phone}', [
      'phone'   => $phoneNumber,
    ]);
    $northstarUser = $this->client->getUser('mobile', $phoneNumber);
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
    $northstarUser = $this->client->getUser('mobile', $phoneNumber);
    if ($northstarUser) {
      return $northstarUser;
    }
    $this->log->warning('Can\'t load user #{phone}', [
      'phone'   => $phoneNumber,
    ]);
    return false;
  }

}
