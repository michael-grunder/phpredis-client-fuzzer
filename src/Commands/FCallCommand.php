<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\FunctionCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class FCallCommand extends FunctionCommand implements FuzzInterface,
                                                              FuzzRawInterface
{
    public function type(): string {
        return self::ANY;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        [$fn, $callback] = $this->randomFunction();

        if ($fn == 'tracking_hset') {
            $key   = $config->getRandomKey('hash');
            $field = $config->getRandomMember('hash');
            $value = $config->getRandomMember('field');

            if ($client instanceof Relay) {
                $cb = rand() & 1 ? $callback : null;
                return $this->exec($client, $fn, [$key], [$field, $value], $cb);
            } else {
                return $this->exec($client, $fn, [$key], [$field, $value]);
            }
        } else if ($fn == 'hash_stats') {
            $key = $config->getRandomKey('hash');
            return $this->exec($client, $fn, [$key], []);
        }

        return null;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$fn] = $this->randomFunction();

        if ($fn === 'tracking_hset') {
            return $this->execRaw(
                $client,
                $fn,
                1,
                $config->getRandomKey(self::HASH),
                $config->getRandomMember(self::HASH),
                $config->getRandomMember('field'),
            );
        } else if ($fn === 'hash_stats') {
            return $this->execRaw(
                $client,
                $fn,
                1,
                $config->getRandomKey(self::HASH),
            );
        }

        throw new \LogicException("Unknown function: {$fn}");
    }
}
