<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class EvalCommand extends Command implements FuzzInterface, FuzzRawInterface {
    /* An array with various LUA scripts for fuzzing.
       Fields: max_keys, write, key_type, script */
    private const SCRIPTS = [
        [0, false, self::ANY, "return 1"],
        [8, false, self::ANY, "redis.call('WATCH', unpack(KEYS))"],
        [8, false, self::ANY,"
            local total = 0
            for i = 1, #KEYS do
                local type = redis.call('TYPE', KEYS[i])
                local cmd
                if type.ok == 'string' then
                    cmd = 'strlen'
                elseif type.ok == 'list' then
                    cmd = 'llen'
                elseif type.ok == 'set' then
                    cmd = 'scard'
                elseif type.ok == 'zset' then
                    cmd = 'zcard'
                elseif type.ok == 'hash' then
                    cmd = 'hlen'
                end
                total = total + tonumber(redis.call(cmd, KEYS[i]))
            end
            return total
        "],
        [8, false, self::LIST, "
            local sum = 0
            for i = 1, #KEYS do
                local val = redis.call('LINDEX', KEYS[i], 0)
                if val then
                    sum = sum + tonumber(val)
                end
            end
            return sum
        "],
        [8, false, self::ANY, "
            local count = 0
            for i = 1, #KEYS do
                if redis.call('EXISTS', KEYS[i]) == 1 then
                    count = count + 1
                end
            end
            return count
      "],
      [8, true, self::INT, "
          local total = 0
          for i = 1, #KEYS do
              total = total + redis.call('INCR', KEYS[i])
          end
          return total
      "],
    ];

    abstract protected function processScript(string $script): string;

    public function type(): string {
        return self::ANY;
    }

    /** @return array{int, bool, string, string} */
    private function pickScript(): array {
        do {
            $script = self::SCRIPTS[array_rand(self::SCRIPTS)];
        } while ($script[1] && !($this->flags() & self::WRITE));

        return $script;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        [$max_keys, $write, $key_type, $script] = $this->pickScript();

        $script = $this->processScript($script);
        $keys = $config->getRandomKeys($key_type, rand(0, $max_keys));

        return $this->exec($client, $script, $keys, count($keys));
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$max_keys, $write, $key_type, $script] = $this->pickScript();

        $script = $this->processScript($script);
        $keys = $config->getRandomKeys($key_type, rand(0, $max_keys));

        return $this->execRaw($client, $script, count($keys), ...$keys);
    }
}
