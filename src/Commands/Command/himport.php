<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class himport extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING | self::STATEFUL;
    }

    /** @return list<string> */
    private function fields(FuzzConfig $config): array {
        return array_values(array_unique(
            $config->getRandomMembers($this->type()),
        ));
    }

    /** @param list<string> $fields */
    private function prepare(Redis|RedisCluster|Relay|Cluster $client,
                             string $hash, string $fieldset, array $fields,
                             bool $raw): mixed
    {
        if ($raw) {
            return $this->execRaw($client, 'PREPARE', $fieldset, ...$fields);
        }

        return $this->exec($client, 'PREPARE', $hash, $fieldset, $fields);
    }

    /** @param list<mixed> $values */
    private function set(Redis|RedisCluster|Relay|Cluster $client,
                         string $hash, string $fieldset, array $values,
                         bool $raw): mixed
    {
        if ($raw) {
            return $this->execRaw($client, 'SET', $hash, $fieldset, ...$values);
        }

        return $this->exec($client, 'SET', $hash, $fieldset, $values);
    }

    private function discard(Redis|RedisCluster|Relay|Cluster $client,
                             string $hash, string $fieldset, bool $raw): mixed
    {
        if ($raw) {
            return $this->execRaw($client, 'DISCARD', $fieldset);
        }

        return $this->exec($client, 'DISCARD', $hash, $fieldset);
    }

    private function discardAll(Redis|RedisCluster|Relay|Cluster $client,
                                string $hash, bool $raw): mixed
    {
        if ($raw) {
            return $this->execRaw($client, 'DISCARDALL');
        }

        return $this->exec($client, 'DISCARDALL', $hash);
    }

    private function exercise(Redis|RedisCluster|Relay|Cluster $client,
                              FuzzConfig $config, bool $raw): mixed
    {
        $operation = rand(0, 3);
        $hash = $config->getRandomKey($this->type());

        if ($operation === 3) {
            return $this->discardAll($client, $hash, $raw);
        }

        $fieldset = 'fieldset:' . $config->getRandomString();
        $fields = $this->fields($config);
        $discarded = false;

        try {
            $result = $this->prepare($client, $hash, $fieldset, $fields, $raw);
            if ($operation === 2) {
                $result = $this->discard($client, $hash, $fieldset, $raw);
                $discarded = true;
                return $result;
            }

            if ($operation === 1) {
                $values = $config->getRandomValues(
                    $client,
                    $this->type(),
                    count($fields),
                );
                $result = $this->set(
                    $client,
                    $hash,
                    $fieldset,
                    array_values($values),
                    $raw,
                );
            }

            return $result;
        } finally {
            if (!$discarded) {
                $this->discard($client, $hash, $fieldset, $raw);
            }
        }
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exercise($client, $config, false);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->exercise($client, $config, true);
    }
}
