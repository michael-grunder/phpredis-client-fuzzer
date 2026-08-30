<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

class VectorAddCommand extends VectorCommand {
    public function flags(): int { return self::WRITE | self::INVALIDATING; }
    /** @return array<int|string,mixed> */
    private function options(): array {
        $o = [];
        if (rand() & 1) $o['REDUCE'] = rand(1, 4);
        if (rand() & 2) $o[] = 'VALUES';
        if (rand() & 4) $o[] = 'CAS';
        if (rand() & 8) $o[] = ['NOQUANT', 'Q8', 'BIN'][rand(0, 2)];
        if (rand() & 16) $o['EF'] = rand(1, 100);
        if (rand() & 32) $o['SETATTR'] = '{"fuzzer":1}';
        if (rand() & 64) $o['M'] = rand(1, 8);
        return $o;
    }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::ANY); $values = $this->values($config);
        return $this->exec($client, $key, $values, $this->element($config), $this->options());
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $key = $config->getRandomKey(self::ANY); $values = $this->values($config); $o = $this->options();
        $args = [$key];
        if (isset($o['REDUCE'])) { $args[]='REDUCE'; $args[]=$o['REDUCE']; }
        if (in_array('VALUES', $o, true)) { $args[]='VALUES'; $args[]=count($values); $args=array_merge($args,$this->rawValues($values)); }
        else { $args[]='FP32'; $args[]=$this->fp32($values); }
        $args[]=$this->element($config);
        if (in_array('CAS', $o, true)) $args[]='CAS';
        foreach (['NOQUANT','Q8','BIN'] as $q) if (in_array($q,$o,true)) $args[]=$q;
        foreach (['EF','SETATTR','M'] as $k) if (isset($o[$k])) { $args[]=$k; $args[]=$o[$k]; }
        return $this->execRaw($client, ...$args);
    }
}
