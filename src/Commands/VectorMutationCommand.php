<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

class VectorMutationCommand extends VectorCommand {
    public function flags(): int { return self::WRITE | self::INVALIDATING; }
    protected function attributes(): string { return '{"fuzzer":true}'; }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::ANY); $element = $this->element($config);
        if ($this->name() === 'vrem') return $this->exec($client, $key, $element);
        return $this->exec($client, $key, $element, $this->attributes());
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $args = [$config->getRandomKey(self::ANY), $this->element($config)];
        if ($this->name() !== 'vrem') $args[] = $this->attributes();
        return $this->execRaw($client, ...$args);
    }
}
