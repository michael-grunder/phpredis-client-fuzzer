<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class keys extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::BLOCKING;
    }

    public function randomPatterh(FuzzConfig $config): string {
        $type = explode(':', $config->getRandomKey($this->type()))[0];

        $max_keys = $config->getMaxKeys();
        $cmd_max_keys = $config->getCmdMaxKeys();

        $digits = floor(log10($max_keys));
        $max_digits = floor(log10($cmd_max_keys));

        if ($digits < 2 || $digits <= $max_digits) {
            return "{$type}:*";
        }

        $base = $digits - $max_digits;
        $random_prefix = rand(1, pow(10, $base) - 1) * pow(10, $max_digits);

        return "{$type}:{$random_prefix}*";
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn($client, $this->randomPatterh($config));
    }
}
