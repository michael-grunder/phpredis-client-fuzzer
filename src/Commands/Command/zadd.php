<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ZAddArgs;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zadd extends Command implements ProxyInterface, FuzzInterface,
                                      FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING | self::COMMON;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return false;

        $len = $this->cmd($server, 'zCard', $key);
        if ( ! is_int($len) || ! $len)
            return false;

        [$lo, $hi] = $this->randomRange($len);

        $values = $this->cmd($server, 'zRange', $key, $lo, $hi, true);
        if ( ! is_array($values) || ! $values)
            return false;

        $args = [$config->getRandomKey($this->type())];
        foreach ($values as $member => $score) {
            $args[] = $score;
            $args[] = $member;
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());

        $args = new ZAddArgs($config);
        $raw = $fn === 'execRaw';
        ScriptLogger::comment('ZADD variant: ' . $args->variant);

        if ($args->variant !== 'plain') {
            // Setup uses the same surface as the final call so prefixes and
            // member codecs agree, including when the sequence is queued.
            if ($raw) {
                $route = self::isCluster($client) ? [$this->clusterRawRoutingKey()] : [];
                $this->cmd($client, 'rawCommand', ...[...$route, 'ZREM', $key, $args->member, $args->otherMember]);
                if ($args->initialScore !== null) {
                    $this->cmd($client, 'rawCommand', ...[...$route, 'ZADD', $key, $args->initialScore, $args->member]);
                }
            } else {
                $this->cmd($client, 'zRem', $key, $args->member, $args->otherMember);
                if ($args->initialScore !== null) {
                    $this->cmd($client, 'zAdd', $key, $args->initialScore, $args->member);
                }
            }
        }

        return $this->$fn($client, $key, ...$args->arguments($raw));
    }
}
