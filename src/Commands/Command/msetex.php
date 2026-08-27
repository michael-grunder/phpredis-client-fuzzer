<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command as BaseCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class msetex extends BaseCommand implements ProxyInterface,
                                           FuzzInterface,
                                           FuzzRawInterface
{
    /**
     * Probability weights for generating TTL argument variants.
     *
     * @var int[]
     */
    private const TTL_VARIANTS = [
        'none'      => 1,
        'seconds'   => 2,
        'seconds-f' => 1,
        'options'   => 3,
    ];

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
       return self::WRITE | self::INVALIDATING;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function randomOptionArray(FuzzConfig $config): array {
        $options = [];

        if (rand(0, 1) === 1) {
            $options[] = rand(0, 1) === 1 ? 'NX' : 'XX';
        }

        $variant = rand(0, 4);
        switch ($variant) {
            case 0:
                $options['EX'] = $config->getRandomExpire();
                break;
            case 1:
                $options['PX'] = $config->getRandomExpire(true);
                break;
            case 2:
                $options['EXAT'] = $config->getRandomExpireAt();
                break;
            case 3:
                $options['PXAT'] = $config->getRandomExpireAt(true);
                break;
            case 4:
                $options[] = 'KEEPTTL';
                break;
        }

        return $options;
    }

    /**
     * @return int|float|array<mixed>|null
     */
    private function randomTtlArgument(FuzzConfig $config): int|float|array|null {
        $pick = $this->weightedRandomChoice(self::TTL_VARIANTS);

        return match ($pick) {
            'none'      => null,
            'seconds'   => $config->getRandomExpire(),
            'seconds-f' => $config->getRandomExpire() + (mt_rand() / mt_getrandmax()),
            'options'   => $this->randomOptionArray($config),
            default     => null,
        };
    }

    /**
     * @param array<string|int, mixed> $options
     * @return array<mixed>
     */
    private function flattenOptions(array $options): array {
        $flat = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $flat = array_merge($flat, $this->flattenOptions($value));
                } else {
                    $flat[] = $value;
                }
                continue;
            }

            if (is_array($value)) {
                $flat[$key] = $value ? $this->flattenOptions($value) : $value;
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param int|float|array<int|string, mixed>|null $ttl
     * @return array<mixed>
     */
    private function ttlArgumentToRawTokens(int|float|array|null $ttl): array {
        if ($ttl === null) {
            return [];
        }

        if (is_int($ttl) || is_float($ttl)) {
            return ['EX', $ttl];
        }

        $options = $this->flattenOptions($ttl);
        $result = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $result[] = $value;
                continue;
            }

            if (is_bool($value)) {
                if ($value) {
                    $result[] = $key;
                }
                continue;
            }

            if (is_array($value)) {
                $result[] = $key;
                foreach ($value as $nested) {
                    $result[] = $nested;
                }
                continue;
            }

            $result[] = $key;
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, int> $weights
     */
    private function weightedRandomChoice(array $weights): string {
        $total = array_sum($weights);
        $pick = rand(1, $total);

        foreach ($weights as $key => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $key;
            }
        }

        $first = array_key_first($weights);
        if ($first === null)
            throw new \InvalidArgumentException('Weights cannot be empty');
        return $first;
    }

    /**
     * @param array<string, mixed> $pairs
     * @param int|float|array<int|string, mixed>|null $ttl
     */
    private function execWithOptionalTtl(Redis|RedisCluster|Relay|Cluster $client,
                                         array $pairs,
                                         int|float|array|null $ttl): mixed
    {
        if ($ttl === null) {
            return $this->exec($client, $pairs);
        }

        return $this->exec($client, $pairs, $ttl);
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $sourceKeys = $keys->consumeKeys($this->type());
        if (!$sourceKeys) {
            return false;
        }

        $values = $this->cmd($client, 'mget', $sourceKeys);
        if (!is_array($values) || !$values) {
            return false;
        }

        $destKeys = $config->getRandomKeys($this->type(), count($values));
        $pairs = array_combine($destKeys, $values);
        $ttl = $this->randomTtlArgument($config);

        return $this->execWithOptionalTtl($client, $pairs, $ttl);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());
        $values = [];
        foreach ($keys as $ignored) {
            $values[] = $config->getRandomValue($client, $this->type());
        }

        $pairs = array_combine($keys, $values);

        $ttl = $this->randomTtlArgument($config);

        return $this->execWithOptionalTtl($client, $pairs, $ttl);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());
        assert(count($keys) > 0);

        $args = [];
        foreach ($keys as $key) {
            $args[] = $key;
            $args[] = $config->getRandomString();
        }

        $ttl = $this->randomTtlArgument($config);
        $args = array_merge($args, $this->ttlArgumentToRawTokens($ttl));

        return $this->execRaw($client, ...$args);
    }
}
