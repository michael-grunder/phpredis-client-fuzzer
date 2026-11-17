<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Mgrunder\RedisClientTester\ScriptLogger;
use Mgrunder\RedisClientTester\Values\DeserializationFailure;
use Mgrunder\RedisClientTester\Values\DeserializationWakeupFailure;

final class DeserializationFailureScenario extends ScenarioBase
{
    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (string $mode): string => 'deserialization-' . $mode,
            [...DeserializationFailure::MODES, 'random', 'recursion']);
    }

    protected function execute(ScenarioRun $run, ?ScenarioPeers $peers): void
    {
        // Independent seeded choices also reproduce when this scenario is called directly.
        $choice = (int) hexdec(substr(hash('sha256', 'deserialization:' . $run->seed()), 0, 7));
        $mode = substr($this->scenario, strlen('deserialization-'));
        if ($mode === 'random') {
            $mode = DeserializationFailure::MODES[$choice % count(DeserializationFailure::MODES)];
        }
        $key = $run->key('callback');
        $value = ($choice & 16) === 0
            ? new DeserializationFailure($run->value('callback'))
            : new DeserializationWakeupFailure($run->value('callback'));
        $serializer = $run->call('getOption', [\Redis::OPT_SERIALIZER]);
        $supported = [\Redis::SERIALIZER_PHP];
        $supported[] = $this->clientConstant($run->client(), 'SERIALIZER_IGBINARY', -1);
        if (!in_array($serializer, $supported, true)) {
            $this->skip('Deserialization failures require PHP or igbinary serialization');
        }

        DeserializationFailure::arm(null);
        ScriptLogger::deserializationFailure($run->client(), $key, null);
        try {
            $run->expect('fixture-written', true, $run->call('set', [$key, $value, ['ex' => 60]]));
            for ($i = 0; $i < 1 + ($choice % 4); $i++) {
                $reply = $run->call('get', [$key], 'warm-cache');
                $run->expectThat('warm-callback-' . $i, 'Decoded the callback fixture',
                    $reply instanceof $value && $reply->payload === $value->payload);
            }
            ScriptLogger::comment('Deserialization failure: mode=' . $mode . ' callback=' . $value::class);
            ScriptLogger::deserializationFailure($run->client(), $key, $mode);
            DeserializationFailure::arm($mode, fn (): mixed => $run->call('get', [$key], 'recursive-read'));
            $run->phase('scheduled-call');
            $call = $run->attempt('get', [$key], 'deserialization-' . $mode);
            $expected = match ($mode) {
                'exception' => \RuntimeException::class,
                'error' => \Error::class,
                'type-error' => \TypeError::class,
                'value-error' => \ValueError::class,
                'parse-error' => \ParseError::class,
                default => null,
            };
            $run->expect('callback-exception', $expected, $call->exception === null ? null : $call->exception::class);
            $run->expectThat('callback-failed', 'Callback threw or terminated the request', $call->exception !== null);
        } finally {
            DeserializationFailure::arm(null);
            ScriptLogger::deserializationFailure($run->client(), $key, null);
        }
        $reply = $run->call('get', [$key], 'recovery-read');
        $run->expectThat('recovery-read', 'Client decodes the fixture after a caught callback exception',
            $reply instanceof $value && $reply->payload === $value->payload);
        $run->call('del', [$key]);
    }
}
