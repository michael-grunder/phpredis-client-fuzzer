<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\OptionCommand;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\OptionName;
use Mgrunder\RedisClientTester\Utilities;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class getoption extends OptionCommand implements FuzzInterface {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::LOCAL;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if (Utilities::randomChance(.1))
            return $this->exec($client, rand(-1024, 1024));

        $opt = new OptionName($this->randomOption($client));

        return $this->exec($client, $opt);
    }
}
