<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class hset extends Command implements ProxyInterface, FuzzInterface,
                                      FuzzRawInterface
{
    public function type(): string {
        return Command::HASH;
    }

    public function flags(): int {
        return Command::WRITE | self::INVALIDATING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return false;

        $hashLength = $this->cmd($server, 'hlen', $key);
        if (!is_int($hashLength) || $hashLength < 1)
            return false;

        $len = rand(1, $hashLength) * -1;

        $fields = $this->cmd($server, 'hRandField', $key,
                             ['count' => $len, 'withvalues' => true]);
        if ( ! $fields ||  ! is_array($fields))
            return false;

        // Combine the real values from with our fuzz-generated field names
        $keys = $config->getRandomMembers($this->type(), count($fields));
        $vals = array_combine($keys, $fields);

        if ( ! $vals)
            return false;

        if (rand() & 1) {
            foreach ($vals as $k => $v) {
                $args[] = $k;
                $args[] = $v;
            }

            return $this->exec($client, $config->getRandomKey($this->type()),
                               ...$args);
        } else {
            return $this->exec($client, $config->getRandomKey($this->type()),
                               $vals);
        }
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key  = $config->getRandomKey($this->type());
        $len  = rand(1, $config->getMembers());
        $rng  = rand();

        $args = [];

        for ($i = 0; $i < $len; $i++) {
            $mem = $config->getRandomMember($this->type());
            $val = $config->getRandomValue($client, $this->type());

            if ($rng & 1) {
                $args[] = $mem;
                $args[] = $val;
            } else {
                $args[$mem] = $val;
            }
        }

        if ($rng & 1) {
            return $this->exec($client, $key, ...$args);
        } else {
            return $this->exec($client, $key, $args);
        }
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];

        $count = rand(1, $config->getMembers());
        for ($i = 0; $i < $count; $i++) {
            $args[] = $config->getRandomMember($this->type());
            $args[] = $config->getRandomString();
        }

        return $this->exec($client, ...$args);
    }
}
