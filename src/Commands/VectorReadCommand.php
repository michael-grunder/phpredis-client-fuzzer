<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

abstract class VectorReadCommand extends VectorCommand {
    public function flags(): int { return self::READ; }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::ANY);
        return match ($this->name()) {
            'vcard', 'vdim', 'vinfo' => $this->exec($client, $key),
            'vrandmember' => $this->exec($client, $key, rand(-2, 4)),
            'vrange' => $this->exec($client, $key, '0', '-1', rand(-1, 4)),
            'vlinks' => $this->exec($client, $key, $this->element($config), (bool) (rand() & 1)),
            'vemb', 'vgetattr' => $this->exec($client, $key, $this->element($config), (bool) (rand() & 1)),
            default => $this->exec($client, $key, $this->element($config)),
        };
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::ANY);
        return match ($this->name()) {
            'vcard', 'vdim', 'vinfo' => $this->execRaw($client, $key),
            'vrandmember' => $this->execRaw($client, $key, rand(-2, 4)),
            'vrange' => $this->execRaw($client, $key, '0', '-1', rand(-1, 4)),
            'vlinks' => $this->rawLinks($client, $key, $this->element($config)),
            'vemb' => $this->execRaw($client, $key, $this->element($config), 'RAW'),
            'vgetattr' => $this->execRaw($client, $key, $this->element($config), 'RAW'),
            default => $this->execRaw($client, $key, $this->element($config)),
        };
    }

    private function rawLinks(Redis|RedisCluster|Relay|Cluster $client, string $key, string $element): mixed {
        if (rand() & 1) return $this->execRaw($client, $key, $element, 'WITHSCORES');
        return $this->execRaw($client, $key, $element);
    }
}
