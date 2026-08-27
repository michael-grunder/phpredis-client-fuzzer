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

class delex extends Command implements FuzzInterface, FuzzRawInterface
{
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE | self::DELETE | self::INVALIDATING;
    }

    private function digestValue(Redis|RedisCluster|Relay|Cluster $client,
                                 mixed $value): string
    {
        $digest = $this->cmd($client, '_digest', $value);

        if (is_string($digest))
            return $digest;
        if (is_scalar($digest) || $digest instanceof \Stringable)
            return (string)$digest;
        throw new \UnexpectedValueException('Digest response is not string-compatible');
    }

    /**
     * @return array<string, mixed>
     */
    private function randomCondition(Redis|RedisCluster|Relay|Cluster $client,
                                     FuzzConfig $config,
                                     bool $raw): array
    {
        $value = $raw
            ? $config->getRandomString()
            : $config->getRandomValue($client, Command::STRING);

        return match (rand(0, 3)) {
            0 => ['IFEQ'  => $value],
            1 => ['IFNE'  => $value],
            2 => ['IFDEQ' => $this->digestValue($client, $value)],
            3 => ['IFDNE' => $this->digestValue($client, $value)],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function randomOptions(Redis|RedisCluster|Relay|Cluster $client,
                                   FuzzConfig $config,
                                   bool $raw = false): ?array
    {
        if (rand(0, 1) === 0) {
            return null;
        }

        $options = $this->randomCondition($client, $config, $raw);

        if (rand(0, 4) === 0) {
            $options = array_merge(
                $options,
                $this->randomCondition($client, $config, $raw)
            );
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function optionsToRawTokens(array $options): array {
        $args = [];

        foreach ($options as $keyword => $value) {
            $args[] = $keyword;
            $args[] = $value;
        }

        return $args;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $options = $this->randomOptions($client, $config);

        if ($options === null) {
            return $this->exec($client, $key);
        }

        return $this->exec($client, $key, $options);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $options = $this->randomOptions($client, $config, true);

        $args = [$key];
        if ($options !== null) {
            $args = array_merge($args, $this->optionsToRawTokens($options));
        }

        return $this->execRaw($client, ...$args);
    }
}
