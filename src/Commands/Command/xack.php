<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xack extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());
        $ids = Events::instance()->previousIds(
            $key, $config->randomMemberCount()
        );

        if ($fn == 'exec')
            return $this->exec($client, $key, 'fuzzer', $ids);

        return $this->execRaw($client, $key, 'fuzzer', ...$ids);
    }
}
