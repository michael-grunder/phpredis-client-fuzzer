<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;

class VectorSimCommand extends VectorCommand {
    public function flags(): int { return self::READ; }
    /** @return array<int|string,mixed> */
    private function options(): array {
        $o=[]; foreach (['VALUES','FP32','ELE'] as $m) if (rand(0,5)===0) { $o[]=$m; break; }
        if (rand()&1) $o[]='WITHSCORES'; if (rand()&2) $o[]='WITHATTRIBS';
        if (rand()&4) $o['COUNT']=rand(1,8); if (rand()&8) $o['EPSILON']=$this->epsilon();
        if (rand()&16) $o['EF']=rand(1,100); if (rand()&32) $o['FILTER']='fuzzer*';
        if (rand()&64) $o['FILTER-EF']=rand(1,100); if (rand()&128) $o[]='TRUTH'; if (rand()&256) $o[]='NOTHREAD';
        return $o;
    }
    private function epsilon(): float { return rand(0,100) / 100.0; }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $element = $this->element($config); $o=$this->options();
        if (in_array('VALUES',$o,true) || in_array('FP32',$o,true)) $element=$this->values($config);
        return $this->exec($client, $config->getRandomKey(self::ANY), $element, $o);
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $values=$this->values($config); $o=$this->options(); $args=[$config->getRandomKey(self::ANY)];
        if (in_array('VALUES',$o,true)) { $args[]='VALUES'; $args[]=count($values); $args=array_merge($args,$this->rawValues($values)); }
        elseif (in_array('FP32',$o,true)) { $args[]='FP32'; $args[]=$this->fp32($values); }
        else { $args[]='ELE'; $args[]=$this->element($config); }
        foreach (['WITHSCORES','WITHATTRIBS'] as $k) if (in_array($k,$o,true)) $args[]=$k;
        foreach (['COUNT','EPSILON','EF','FILTER','FILTER-EF'] as $k) if (isset($o[$k])) { $args[]=$k; $args[]=$o[$k]; }
        foreach (['TRUTH','NOTHREAD'] as $k) if (in_array($k,$o,true)) $args[]=$k;
        return $this->execRaw($client, ...$args);
    }
}
