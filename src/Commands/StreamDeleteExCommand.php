<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

class StreamDeleteExCommand extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string { return self::STREAM; }
    public function flags(): int { return self::WRITE | self::DELETE | self::INVALIDATING; }
    /** @return list<string> */
    protected function ids(string $key, FuzzConfig $config): array {
        return array_values(Events::instance()->previousIds($key, rand(1, $config->getMembers())));
    }
    protected function mode(): ?string { return [null, 'KEEPREF', 'DELREF', 'ACKED'][rand(0, 3)]; }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::STREAM); $ids = $this->ids($key, $config);
        $args = [$key];
        if ($this->name() === 'xackdel') $args[] = 'fuzzer';
        $args[] = $ids; $mode = $this->mode(); if ($mode !== null) $args[] = $mode;
        return $this->exec($client, ...$args);
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::STREAM); $ids = $this->ids($key, $config);
        $args = [$key]; if ($this->name() === 'xackdel') $args[] = 'fuzzer';
        $mode = $this->mode(); if ($mode !== null) $args[] = $mode;
        $args[] = 'IDS'; $args[] = count($ids); foreach ($ids as $id) $args[] = $id;
        return $this->execRaw($client, ...$args);
    }
}
