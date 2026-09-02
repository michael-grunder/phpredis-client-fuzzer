<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;

return static function (HookRegistry $hooks): void {
    if (!defined('Redis::SERIALIZER_MSGPACK')) {
        return;
    }

    $hooks->deny(
        id: 'no-msgpack-serializer',
        method: 'setOption',
        arguments: [
            Redis::OPT_SERIALIZER,
            constant('Redis::SERIALIZER_MSGPACK'),
        ],
        reason: 'Known memory leaks and crashes in the msgpack serializer',
    );
};
