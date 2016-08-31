<?php

// --- Composer ---
require __DIR__ . '/vendor/autoload.php';

// Load dotenv.
$dotenv = new josegonzalez\Dotenv\Loader('./.env');
$dotenv->parse()->define();

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
use DoSomething\Northstar\NorthstarClient;
use DoSomething\Vcard\MobileCommonsLoader;
use Monolog\Logger;
// use Monolog\Handler\StreamHandler;
// use Monolog\Handler\ErrorLogHandler;
use Monolog\Processor\PsrLogMessageProcessor;
// use Monolog\Formatter\LineFormatter;

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
$northstar = new NorthstarClient($northstar_config);
$moco = new MobileCommonsLoader($moco_config, $log);
$redis = new Redis();
$redis->pconnect(REDIS_HOST, REDIS_PORT);
define("REDIS_KEY", 'vcard:moco_users');
