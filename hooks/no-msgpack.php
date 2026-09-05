<?php

declare(strict_types=1);

/**
 * Invocation hook example: a safety guard that rejects one argument tuple.
 *
 * The observational lifecycle events (postConstructor, preCommand,
 * postCommand, preDestructor) are demonstrated in hooks/lifecycle-example.php.
 * A single hook file can register both kinds on the same HookRegistry.
 */

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

return static function (HookRegistry $hooks): void {
    if (!defined('Redis::SERIALIZER_MSGPACK')) {
        return;
    }

    $hooks->addGuard(
        'no-msgpack-serializer',
        new readonly class(
            Redis::OPT_SERIALIZER,
            constant('Redis::SERIALIZER_MSGPACK'),
        ) implements InvocationHook {
            public function __construct(
                private int $serializerOption,
                private int $msgpackSerializer,
            ) {
            }

            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                if (strcasecmp($invocation->method, 'setOption') !== 0
                    || count($invocation->arguments) < 2
                    || $this->integerValue($invocation->arguments[0]) !== $this->serializerOption
                    || $this->integerValue($invocation->arguments[1]) !== $this->msgpackSerializer) {
                    return HookDecision::allow();
                }

                return HookDecision::reject(
                    'Value would select the msgpack serializer',
                );
            }

            /** Match the integer conversion used by PhpRedis and Relay options. */
            private function integerValue(mixed $value): int
            {
                // Standard PHP objects convert to 1 with a warning. Avoid
                // producing that warning in the guard itself.
                return is_object($value) ? 1 : (int) $value;
            }
        },
    );

    /* Lifecycle hooks can be registered right here alongside the guard, for
       instance to see every value this guard let through:

       $hooks->onPreCommand('log-setoption', static function (
           \Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation $invocation,
       ): void {
           if (strcasecmp($invocation->method, 'setOption') === 0) {
               fwrite(STDERR, var_export($invocation->arguments, true) . "\n");
           }
       }); */
};
