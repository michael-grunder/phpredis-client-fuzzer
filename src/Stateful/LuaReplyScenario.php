<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/** Bounded Lua replies and SHA reuse on the same primary, without changing keys. */
final class LuaReplyScenario extends ScenarioBase
{
    protected function requiredMethods(Redis|RedisCluster|Relay|Cluster $client): array
    {
        return ['getMode', 'eval', 'evalsha'];
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        $key = $run->key('route');
        $value = $run->value('lua') . "\0binary";
        $arguments = [$key, $value, ''];
        $fixtures = [
            ['return #KEYS', 1],
            ['return ARGV[1]', $value],
            ['return {ARGV[1], {42, ARGV[2]}, #KEYS}', [$value, [42, ''], 1]],
        ];

        $run->phase('scheduled-call');
        foreach ($fixtures as $index => [$script, $expected]) {
            // EVAL primes the script cache on the same keyed primary used by EVALSHA.
            $run->expect('lua-' . $index . ':eval', $expected,
                $run->call('eval', [$script, $arguments, 1]));
            $run->expect('lua-' . $index . ':evalsha', $expected,
                $run->call('evalsha', [sha1($script), $arguments, 1]));
        }
    }
}
