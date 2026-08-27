<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;


use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\ScriptClosure;

abstract class FunctionCommand extends Command {
    /** @var array<string, list<array{string, ScriptClosure}>> */
    protected array $callbacks = [];

    protected CONST LUA_LIBRARIES = [
        'fuzzlib1' =>
<<<END
#!lua name=fuzzlib1

local function tracking_hset(keys, args)
    local time = redis.call('TIME')[1]

    table.insert(args, 'time_updated')
    table.insert(args, time)

    redis.call('HSET', keys[1], unpack(args))
    redis.call('HSETNX', keys[1], 'time_created', time)
    local created = redis.call('HGET', keys[1], 'time_created')

    return {created, time}
end

local function hash_stats(keys, args)
    return redis.call('HMGET', keys[1], 'time_created', 'time_updated')
end

redis.register_function('tracking_hset', tracking_hset)
redis.register_function{
    function_name='hash_stats',
    callback=hash_stats,
    flags={ 'no-writes' }
}
END,
];

    public function __construct() {
        $this->callbacks = [
            'read' => [
                [
                    'hash_stats',
                    new ScriptClosure(fn($v) => false, 'fn($v) => strlen($v)'),
                ]
            ],
            'write' => [
                [
                    'tracking_hset',
                    new ScriptClosure(fn($v) => false, 'fn($v) => count($v)'),
                ],
            ]
        ];
    }

    /** @return array{string, ScriptClosure} */
    protected function randomFunction(): array {
        $key = ($this->flags() & Command::WRITE) ? 'write' : 'read';
        $rng = array_rand($this->callbacks[$key]);

        return $this->callbacks[$key][$rng];
    }
}
