<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

class xnack extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string { return self::STREAM; }
    public function flags(): int { return self::WRITE | self::INVALIDATING; }
    /** @return list<string> */
    private function ids(string $key, FuzzConfig $config): array {
        return array_values(Events::instance()->previousIds($key, rand(1, $config->getMembers())));
    }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::STREAM);
        $ids = $this->ids($key, $config);
        $options = [];
        if (rand() & 1) $options['RETRYCOUNT'] = rand(0, 4);
        if (rand() & 2) $options[] = 'FORCE';
        $args = [$key, 'fuzzer', ['TIME', 'IDLE'][rand(0, 1)], $ids];
        if ($options !== []) $args[] = $options;
        return $this->exec($client, ...$args);
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::STREAM); $ids = $this->ids($key, $config);
        $args = [$key, 'fuzzer', ['TIME', 'IDLE'][rand(0, 1)], 'IDS', count($ids)];
        foreach ($ids as $id) $args[] = $id;
        if (rand() & 1) { $args[] = 'RETRYCOUNT'; $args[] = rand(0, 4); }
        if (rand() & 2) $args[] = 'FORCE';
        return $this->execRaw($client, ...$args);
    }
}
