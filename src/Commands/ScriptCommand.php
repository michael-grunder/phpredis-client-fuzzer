<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;

class ScriptCommand extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string { return self::NONE; }
    public function flags(): int { return self::ADMIN | self::LOCAL; }
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $forms = [
            ['exists', str_repeat('0', 40), str_repeat('f', 40)],
            ['flush'], ['flush', 'async'], ['flush', 'sync'], ['kill'],
            ['load', 'return 1'], ['show', 'return 1'], ['debug', 'no'],
        ];
        $args = $forms[rand(0, count($forms) - 1)];
        if ($client instanceof RedisCluster || $client instanceof Cluster)
            array_unshift($args, $config->getRandomKey(self::ANY));
        return $this->exec($client, ...$args);
    }
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $forms = [['EXISTS', str_repeat('0', 40)], ['FLUSH'], ['FLUSH', 'ASYNC'],
                  ['FLUSH', 'SYNC'], ['KILL'], ['LOAD', 'return 1'],
                  ['SHOW', 'return 1'], ['DEBUG', 'NO']];
        return $this->execRaw($client, ...$forms[rand(0, count($forms) - 1)]);
    }
}
