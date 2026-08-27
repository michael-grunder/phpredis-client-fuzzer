<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\OptionCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Utilities;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionName;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionValue;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class setoption extends OptionCommand implements FuzzInterface {
    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::LOCAL;
    }

    private function randomReadTimeout(FuzzConfig $config): int|float|string {
        $rand = rand();

        if ($rand & 1)
            return $this->randFloatInRange(PHP_FLOAT_MIN, PHP_FLOAT_MAX);
        else if ($rand & 2)
            return rand(PHP_INT_MIN, PHP_INT_MAX);
        else if ($rand & 4)
            return $config->getRandomString();

        /* Sane range */
        return $this->randFloatInRange(0.0005, 1.5);
    }

    private function getOptionValue(
        Redis|RedisCluster|Relay|Cluster $client,
        FuzzConfig $config,
        int $option,
    ): mixed {
        return match ($option) {
            Redis::OPT_PREFIX => $config->getRandomPrefix(),
            Redis::OPT_SERIALIZER => $this->randomSerializer(),
            Redis::OPT_COMPRESSION => $this->randomCompression(),
            Redis::OPT_COMPRESSION_LEVEL => rand() & 1 ? rand(-99, 99) : rand(-150000,150000),
            Redis::OPT_READ_TIMEOUT => $this->randomReadTimeout($config),
            Redis::OPT_SCAN => $this->randomScanOption(),
            Redis::OPT_TCP_KEEPALIVE => rand() & 1,
            Redis::OPT_MAX_RETRIES => rand(0, 25),
            Redis::OPT_BACKOFF_ALGORITHM => $this->randomBackoffAlgorithm(),
            Redis::OPT_BACKOFF_BASE => rand(0, 200),
            Redis::OPT_BACKOFF_CAP => rand(0, 500),
            RedisCluster::OPT_SLAVE_FAILOVER => $this->randomFailover(),
            default => $this->getExtensionOptionValue($client, $config, $option),
        };
    }

    private function getExtensionOptionValue(
        Redis|RedisCluster|Relay|Cluster $client,
        FuzzConfig $config,
        int $option,
    ): mixed {
        $constants = [
            'Redis::OPT_NULL_MULTIBULK_AS_NULL' => static fn (): int => rand() & 2,
            'Redis::OPT_REPLY_LITERAL' => static fn (): int => rand() & 1,
        ];
        if ($this->isRelay($client)) {
            $constants += [
                'Relay\\Relay::OPT_NULL_MULTIBULK_AS_NULL' => static fn (): int => rand() & 2,
                'Relay\\Relay::OPT_REPLY_LITERAL' => static fn (): int => rand() & 1,
                'Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY' => static fn (): int => rand() & 1,
                'Relay\\Relay::OPT_THROW_ON_ERROR' => static fn (): int => rand() & 1,
                'Relay\\Relay::OPT_USE_CACHE' => static fn (): bool => Utilities::randomChance(.8),
                'Relay\\Relay::OPT_CLIENT_INVALIDATIONS' => static fn (): int => rand() & 1,
                'Relay\\Relay::OPT_ALLOW_PATTERNS' => static fn (): ?string =>
                    rand() & 7 ? null : '*' . rand(1, 9) . '*',
                'Relay\\Relay::OPT_IGNORE_PATTERNS' => static fn (): ?string =>
                    rand() & 7 ? null : '*' . $config->getRandomType() . '*',
            ];
        }

        foreach ($constants as $constant => $value) {
            if (defined($constant) && constant($constant) === $option) {
                return $value();
            }
        }

        throw new \LogicException("Unknown client option: {$option}");
    }

    private function getRandomValue(Redis|RedisCluster|Relay|Cluster $client,
                                    FuzzConfig $config): mixed
    {
        return match (rand() % 7) {
            0 => false,
            1 => true,
            2 => null,
            3 => $config->getRandomString(),
            4 => rand(),
            5 => $config->getRandomMembers($config->getRandomType()),
            6 => $config->getRandomValue($client, $config->getRandomType()),
        };
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        if (Utilities::randomChance(.1)) {
            return $this->exec($client, rand(-1024, 1024),
                               $this->getRandomValue($client, $config));
        }

        $option = $this->randomOption($client);
        $value  = match(rand() & 1) {
            0 => $this->getOptionValue($client, $config, $option),
            1 => $this->getRandomValue($client, $config),
        };

        $opt = new OptionName($option);
        $val = new OptionValue($option, $value);

        return $this->exec($client, $opt, $val);
    }
}
