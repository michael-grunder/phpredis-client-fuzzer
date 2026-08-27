<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;


use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;

//use Mgrunder\PhpredisCommandFuzzer\Commands\Command\get;
//use Mgrunder\PhpredisCommandFuzzer\Commands\Command\smembers;
//use Mgrunder\PhpredisCommandFuzzer\Commands\Command\hgetall;
//use Mgrunder\PhpredisCommandFuzzer\Commands\Command\lrange;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

/* A bit of a meta-fuzz testing class which exists to use rawCommand with a
 * random number of arguments, to fuzz improper command execution */
class rawcommand extends Command implements FuzzRawInterface {
    private const COMMANDS = [
        'get' => 1, 'smembers' => 1, 'hgetall' => 1, 'lrange' => 1
    ];

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::WRITE;
    }

    private function className(string $class): string {
        return 'Mgrunder\\RedisClientFuzzer\\Commands\\Command\\' . $class;
    }

    private function randomArg(FuzzConfig $config): mixed {
        switch (rand() % 4) {
            case 0:
                return $config->getRandomKey(self::ANY);
            case 1:
                return $config->getRandomString();
            case 2:
                return $config->getRandomInt();
            case 3:
                return $config->getRandomFloat();
        }
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $cmd = $this->className(array_rand(self::COMMANDS));
        $obj = new $cmd;
        if ( ! $obj instanceof Command)
            throw new \UnexpectedValueException("{$cmd} is not a command");
        $rng = rand() % 8;

        if (($client instanceOf Cluster) || ($client instanceOf RedisCluster))
            $args = [$obj->name(), $config->getRandomKey(self::ANY)];
        else
            $args = [$obj->name()];

        while ($rng--)
            $args[] = $this->randomArg($config);

        return $this->exec($client, ...$args);
    }
}
