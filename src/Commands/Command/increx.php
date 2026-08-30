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

class increx extends Command implements FuzzInterface, FuzzRawInterface {
    private const OVERFLOW = ['FAIL', 'SAT', 'REJECT'];

    public function type(): string {
        return self::INT;
    }

    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }

    private function increment(FuzzConfig $config): int|float {
        return rand() & 1
            ? $config->getRandomInt()
            : $config->getRandomFloat();
    }

    /** @return array<int|string, mixed> */
    private function options(FuzzConfig $config, int|float $increment): array {
        $options = [];
        $bounds = is_int($increment)
            ? [$config->getRandomInt(), $config->getRandomInt()]
            : [$config->getRandomFloat(), $config->getRandomFloat()];
        sort($bounds, SORT_NUMERIC);

        if (rand() & 1)
            $options['LBOUND'] = $bounds[0];
        if (rand() & 1)
            $options['UBOUND'] = $bounds[1];
        if (rand() & 1)
            $options['OVERFLOW'] = self::OVERFLOW[array_rand(self::OVERFLOW)];

        $expiry = match (rand(0, 5)) {
            0 => null,
            1 => 'EX',
            2 => 'PX',
            3 => 'EXAT',
            4 => 'PXAT',
            5 => 'PERSIST',
        };

        if ($expiry === 'EX') {
            $options[$expiry] = $config->getRandomExpire();
        } else if ($expiry === 'PX') {
            $options[$expiry] = $config->getRandomExpire(true);
        } else if ($expiry === 'EXAT') {
            $options[$expiry] = $config->getRandomExpireAt();
        } else if ($expiry === 'PXAT') {
            $options[$expiry] = $config->getRandomExpireAt(true);
        } else if ($expiry === 'PERSIST') {
            $options[$expiry] = true;
        }

        if ($expiry !== null && $expiry !== 'PERSIST' && rand() & 1)
            $options[] = 'ENX';

        return $options;
    }

    /**
     * @param array<int|string, mixed> $options
     * @return array<mixed>
     */
    private function rawOptions(array $options): array {
        $result = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $result[] = $value;
            } else if ($key === 'PERSIST') {
                $result[] = $key;
            } else if ($key === 'OVERFLOW') {
                /* Relay's method API still exposes the original three-mode
                   option. The current server protocol replaced it with a
                   single SATURATE token; FAIL and REJECT now map to the
                   default non-saturating behavior. */
                if ($value === 'SAT')
                    $result[] = 'SATURATE';
            } else {
                $result[] = $key;
                $result[] = $value;
            }
        }

        return $result;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $variant = rand(0, 5);
        $increment = $this->increment($config);
        $keyType = is_float($increment) ? self::FLOAT : self::INT;
        $key = $config->getRandomKey($keyType);

        return match ($variant) {
            0 => $this->exec($client, $key),
            1 => $this->exec($client, $key, null),
            2 => $this->exec($client, $key, $increment),
            3 => $this->exec($client, $key, $increment, []),
            4 => $this->exec(
                $client,
                $key,
                null,
                $this->options($config, 1),
            ),
            5 => $this->exec(
                $client,
                $key,
                $increment,
                $this->options($config, $increment),
            ),
        };
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $increment = rand(0, 2) === 0 ? null : $this->increment($config);
        $typeIncrement = $increment ?? 1;
        $keyType = is_float($increment) ? self::FLOAT : self::INT;
        $key = $config->getRandomKey($keyType);
        $options = $this->options($config, $typeIncrement);
        $args = [$key];

        if (is_int($increment)) {
            array_push($args, 'BYINT', $increment);
        } else if (is_float($increment)) {
            array_push($args, 'BYFLOAT', $increment);
        }

        array_push($args, ...$this->rawOptions($options));

        return $this->execRaw($client, ...$args);
    }
}
