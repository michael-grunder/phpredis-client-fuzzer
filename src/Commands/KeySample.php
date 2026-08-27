<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class KeySample {
    private Redis|RedisCluster|Relay|Cluster $client;
    private int $max_keys_per_type;

    /** @var array<string, array<string, true>> */
    private array $keys = [];

    public function __construct(Redis|RedisCluster|Relay|Cluster $client,
                                int $max_keys_per_type = 100)
    {
        $this->client = $client;
        $this->max_keys_per_type = $max_keys_per_type;
    }

    public function empty(): bool {
        return empty($this->keys);
    }

    public function consumeKey(string $type): ?string {
        if (empty($this->keys))
            return null;
        else if ($type != Command::ANY && ! isset($this->keys[$type]))
            return null;

        if ($type == Command::ANY)
            $type = array_rand($this->keys);

        $key = array_rand($this->keys[$type]);

        unset($this->keys[$type][$key]);
        if ( ! $this->keys[$type])
            unset($this->keys[$type]);

        return $key;
    }

    /**
     * @return string[]|null
     */
    public function consumeNKeys(string $type, int $count): ?array {
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $this->consumeKey($type);
            if ( ! $key)
                return null;
            $result[] = $key;
        }

        return $result;
    }

    /** @return string[]|null */
    protected function consumeAllKeys(): ?array {
        $result = [];

        if ( ! $this->keys)
            return null;

        foreach ($this->keys as $type => $keys) {
            foreach ($keys as $key => $dummy) {
                $result[] = $key;
            }
        }

        $this->keys = [];

        return $result;
    }

    /** @return string[]|null */
    public function consumeKeys(string $type): ?array {
        if ($type == Command::ANY)
            return $this->consumeAllKeys();
        else if ( ! isset($this->keys[$type]))
            return null;

        $keys = array_keys($this->keys[$type]);
        unset($this->keys[$type]);

        return $keys;
    }

    public function hasKeys(string $type): bool {
        if ($type == Command::ANY)
            return ! empty($this->keys);
        else
            return isset($this->keys[$type]);
    }

    /**
     * @return array<string, int>
     */
    private function getKeyTypesCluster(RedisCluster|Cluster $client,
                                        int $count): array
    {
        $keys = $types = [];

        for ($i = 0; $i < $count; $i++) {
            $keys[] = $client->randomKey("fuzzer:route:{$i}");
        }
        foreach ($keys as $key) {
            /** #var $type int */
            $types[] = $client->type($key);
        }

        /** @var string[] $keys */
        /** @var int[] $types */
        return array_combine($keys, $types);
    }

    /**
     * @return array<string, int>
     */
    private function getKeyTypesStandalone(Redis|Relay $client,
                                           int $count): array
    {
        $client->pipeline();

        for ($i = 0; $i < $count; $i++) {
            $client->randomKey();
        }

        $keys = $client->exec();
        if ( ! is_array($keys))
            throw new \Exception("Expected array, got " . gettype($keys));

        $client->pipeline();
        foreach ($keys as $key) {
            $client->type($key);
        }

        $types = $client->exec();
        if ( ! is_array($types))
            throw new \Exception("Expected array, got " . gettype($types));

        $result = [];
        foreach ($keys as $index => $key) {
            $type = $types[$index] ?? null;
            if (is_string($key) && is_int($type)) {
                $result[$key] = $type;
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    protected function getKeyTypes(int $count) {
        if ($this->client instanceOf Redis || $this->client instanceOf Relay) {
            $ktypes = $this->getKeyTypesStandalone($this->client, $count);
        } else {
            $ktypes = $this->getKeyTypesCluster($this->client, $count);
        }

        foreach ($ktypes as $key => $type)
            $ktypes[$key] = Command::toTypeString($type);

        /** @var array<string, string> */
        return $ktypes;
    }

    private function countType(string $type): int {
        if ($type === Command::ANY) {
            return (int) array_reduce(
                $this->keys,
                fn($acc, $keys) => $acc + count($keys),
                0
            );
        }

        return isset($this->keys[$type]) ? count($this->keys[$type]) : 0;
    }

    public function sampleCommandKeys(Command $cmd, int $count, int $max_tries): bool {
        for ($i = 0; $i < $max_tries; $i++) {
            if ($this->countType($cmd->type()) > 0)
                return true;
            $this->sampleKeys($count);
        }

        return false;
    }

    public function sampleKeys(int $count): bool {
        if ($count < 1)
            throw new \Exception("count must be > 0 (value: $count)");

        try {
            $ktypes = $this->getKeyTypes($count);
            foreach ($ktypes as $key => $type) {
                if (count($this->keys[$type] ?? []) >= $this->max_keys_per_type)
                    continue;
                $this->keys[$type][$key] = true;
            }
        } catch (\Exception $ex) {
            $this->client->discard();
            return false;
        }

        return true;
    }
}
