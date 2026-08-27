<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xread extends Command implements FuzzInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::READ | self::BLOCKING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $keys = array_flip($config->getRandomKeys($this->type()));
        foreach ($keys as $key => &$id) {
            $id = Events::instance()->randomReadId();
        }

        $block = rand() & 1 ? $config->getRandomTimeoutMs() : -1;

        return $this->exec($client, $keys, $block);
    }
}

/**
 * Consume one or more unconsumed elements in one or more streams.
 *
 * @param array $streams An associative array with stream name keys and minimum id values.
 * @param int   $count   An optional limit to how many entries are returned *per stream*
 * @param int   $block   An optional maximum number of milliseconds to block the caller if no
 *                       data is available on any of the provided streams.
 *
 * @return Redis|array|bool An array of read elements or false if there aren't any.
 *
 * @see https://redis.io/commands/xread
 *
 * @example
 * $redis->xAdd('s03', '3-1', ['title' => 'The Search, Part I']);
 * $redis->xAdd('s03', '3-2', ['title' => 'The Search, Part II']);
 * $redis->xAdd('s03', '3-3', ['title' => 'The House Of Quark']);
 * $redis->xAdd('s04', '4-1', ['title' => 'The Way of the Warrior']);
 * $redis->xAdd('s04', '4-3', ['title' => 'The Visitor']);
 * $redis->xAdd('s04', '4-4', ['title' => 'Hippocratic Oath']);
 *
 * $redis->xRead(['s03' => '3-2', 's04' => '4-1']);
 */
// public function xread(array $streams, int $count = -1, int $block = -1): Redis|array|bool;
