<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xclaim extends Command implements FuzzInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    /** @return array<int|string, int|string> */
    private function randomOptions(FuzzConfig $config): array {
        $result = [];

        $rng = rand();

        if ($rng & 0x1)
            $resul['IDLE'] = $config->getRandomTimeoutMs();
        if ($rng & 0x2)
            $resul['TIME'] = 1000 * time() + rand(0, 100);
        if ($rng & 0x4)
            $resul['RETRYCOUNT'] = rand(0, 4);
        if ($rng & 0x8)
            $result[] = 'FORCE';
        if ($rng & 0x10)
            $result[] = 'JUSTID';

        return $result;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey($this->type());

        return $this->exec(
            $client,
            $key,
            'fuzzer',
            $config->getConsumer(),
            rand(0, $config->getRandomTimeoutMs()),
            Events::instance()->previousIds($key, $config->randomMemberCount()),
            $this->randomOptions($config),
        );
    }
}
