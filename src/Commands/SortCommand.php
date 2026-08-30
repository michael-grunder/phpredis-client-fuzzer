<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class SortCommand extends Command implements FuzzInterface,
                                                     FuzzRawInterface
{
    private const SOURCE_TYPES = [self::LIST, self::SET, self::ZSET];
    private const ORDERS = ['ASC' => true, 'DESC' => true];

    abstract protected function canStore(): bool;

    public function type(): string {
        /* SORT accepts list, set, and sorted-set source keys. */
        return self::ANY;
    }

    public function flags(): int {
        if ($this->canStore())
            return self::WRITE | self::INVALIDATING;

        return self::READ;
    }

    private function pattern(FuzzConfig $config): string {
        return rand(0, 3) === 0 ? '#' : $config->getRandomPattern();
    }

    /** @return array<int|string, mixed> */
    private function options(FuzzConfig $config): array {
        $options = [];

        if (rand() & 1)
            $options['BY'] = $this->pattern($config);

        if (rand() & 1)
            $options['LIMIT'] = [rand(0, $config->getMembers()), rand(0, $config->getMembers())];

        if (rand() & 1) {
            $get = [$this->pattern($config)];
            $getRng = rand();
            if ($getRng & 1)
                $get[] = $this->pattern($config);
            $options['GET'] = $getRng & 2 ? $get : $get[0];
        }

        if (rand() & 1)
            $options['SORT'] = array_rand(self::ORDERS);

        if (rand() & 1)
            $options['ALPHA'] = true;

        if ($this->canStore() && rand(0, 3) === 0)
            $options['STORE'] = $config->getRandomKey(self::LIST);

        return $options;
    }

    /** @return array{0: string, 1: array<int|string, mixed>} */
    private function arguments(FuzzConfig $config): array {
        $sourceType = self::SOURCE_TYPES[array_rand(self::SOURCE_TYPES)];
        $source = $config->getRandomKey($sourceType);

        return [$source, $this->options($config)];
    }

    /**
     * @param array<int|string, mixed> $options
     * @return array<mixed>
     */
    private function rawOptions(array $options): array {
        $result = [];

        foreach ($options as $key => $value) {
            if ($key === 'GET') {
                foreach (is_array($value) ? $value : [$value] as $pattern)
                    array_push($result, 'GET', $pattern);
            } else if ($key === 'LIMIT' && is_array($value)) {
                array_push($result, 'LIMIT', $value[0], $value[1]);
            } else if ($key === 'ALPHA') {
                if ($value)
                    $result[] = 'ALPHA';
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
        [$source, $options] = $this->arguments($config);

        if (rand() & 1)
            return $this->exec($client, $source);

        return $this->exec($client, $source, $options);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$source, $options] = $this->arguments($config);

        return $this->execRaw($client, $source, ...$this->rawOptions($options));
    }
}
