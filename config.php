<?php

// PHP settings.
gc_enable();
ini_set('memory_limit', '-1');
error_reporting(E_ALL);

// --- Composer ---
require __DIR__ . '/vendor/autoload.php';

// Load dotenv.
$dotenv = new josegonzalez\Dotenv\Loader('./.env');
$dotenv->parse()->define();

// Check that everything is set.
$requiredConstants = [
  'NORTHSTAR_URL',
  'NORTHSTAR_API_KEY',
  'MOCO_USERNAME',
  'MOCO_PASSWORD',
  'BITLY_ACCESS_TOKEN',
  'REDIS_HOST',
  'REDIS_PORT',
];
foreach ($requiredConstants as $requiredConstant) {
  if (!defined($requiredConstant)) {
    exit($requiredConstant . ' must be declared in .env file.' . PHP_EOL);
  }
}

// --- Settings ---
// Northstar
$northstar_config = [
  'url'     => NORTHSTAR_URL,
  'api_key' => NORTHSTAR_API_KEY,
];

// Mobile commons.
$moco_config = array(
  'username' => MOCO_USERNAME,
  'password' => MOCO_PASSWORD,
);

// --- Imports ---
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Zend\ProgressBar\Adapter\Console as AdapterConsole;

// --- DS Imports ---
use DoSomething\Northstar\NorthstarClient;
use DoSomething\Vcard\MobileCommonsLoader;

// --- Logger ---
$log = new Logger('vcard');
$log->pushProcessor(new PsrLogMessageProcessor());
// the default date format is "Y-m-d H:i:s"
$dateFormat = "Y-m-d H:i:s";
// the default output format is "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n"
$output = "[%datetime%] %level_name%: %message%.";

// Console.
// $logConsoleStream = new ErrorLogHandler();
// $logConsoleStream->setFormatter(new LineFormatter($output, $dateFormat));
// $log->pushHandler($logConsoleStream);


// --- Objects ---
// Northstar.
$northstar = new NorthstarClient($northstar_config);
// Mobile Commons
$moco = new MobileCommonsLoader($moco_config, $log);
// Bitly
// $bitly = new \Hpatoio\Bitly\Client(BITLY_ACCESS_TOKEN);
// Redis
$redis = new Redis();
$redis->pconnect(REDIS_HOST, REDIS_PORT);
$redisRead = new Redis();
$redisRead->connect(REDIS_HOST, REDIS_PORT);
define("REDIS_KEY", 'vcard:moco_users');
// Zend Progress Bar
$progressAdapter = new AdapterConsole();
$progressAdapter->setElements([
  AdapterConsole::ELEMENT_PERCENT,
  AdapterConsole::ELEMENT_BAR,
  AdapterConsole::ELEMENT_TEXT,
  AdapterConsole::ELEMENT_ETA,
]);
