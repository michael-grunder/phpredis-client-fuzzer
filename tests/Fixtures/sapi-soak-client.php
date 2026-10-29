<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mgrunder\RedisClientTester\Cli\SapiSoakApplication;

$autoload = $argv[1] ?? throw new RuntimeException('missing fixture autoload');
exit((new SapiSoakApplication($autoload))->run(['client', ...array_slice($argv, 2)]));
