<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

class RestoreCommand extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string { return self::ANY; }
    public function flags(): int { return self::WRITE | self::INVALIDATING; }

    /** @return array<int|string,int|string|bool> */
    private function options(): array {
        $o = [];
        $r = rand() % 8;
        if ($r & 1) $o[] = 'REPLACE';
        if ($r & 2) $o[] = 'ABSTTL';
        if ($r & 4) $o['IDLETIME'] = rand(0, 1000);
        if (rand() & 1) {
            $frequencyName = rand(0, 1) === 0 ? 'FREQ' : 'FREQUENCY';
            $o[$frequencyName] = rand(0, 1000);
        }
        return $o;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $args = [$config->getRandomKey(self::ANY), rand(0, 1000), $config->getRandomBytes(16)];
        if (rand() % 4 === 0) return $this->exec($client, ...$args);
        $args[] = $this->options();
        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $args = [$config->getRandomKey(self::ANY), rand(0, 1000), $config->getRandomBytes(16)];
        $o = $this->options();
        foreach ($o as $k => $v) {
            if (is_int($k)) $args[] = $v;
            else {
                $name = strtoupper((string) $k);
                if ($name === 'FREQUENCY') $name = 'FREQ';
                $args[] = $name; $args[] = $v;
            }
        }
        return $this->execRaw($client, ...$args);
    }
}
