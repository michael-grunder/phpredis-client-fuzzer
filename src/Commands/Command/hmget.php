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

class hmget extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function type(): string {
        return self::HASH;
    }

    /** @return string[]|null */
    protected function randomFields(Redis|Relay $server, string $hash): ?array {
        $fields = $this->cmd($server, 'hKeys', $hash);
        if ( ! is_array($fields) || ! count($fields)) {
            return null;
        }

        $fields = array_values(array_filter($fields, 'is_string'));
        if ($fields === []) {
            return null;
        }

        return array_slice($fields, 0, rand(1, count($fields)));
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());
        $fields = $config->getRandomMembers($this->type());

        if ($fn === 'exec')
            return $this->exec($client, $key, $fields);

        return $this->execRaw($client, $key, ...$fields);
    }
}
