<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\OptionCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionName;
use Mgrunder\PhpredisCommandFuzzer\Utilities;

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
