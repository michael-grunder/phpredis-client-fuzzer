<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class HookRandomnessTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::setLifecycleHooks(null);
    }

    public function testRandomnessIsUnavailableBeforeTheRegistryIsSeeded(): void
    {
        $hooks = new HookRegistry();

        self::assertNull($hooks->seed());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('setSeed()');
        $hooks->randomizer('anything');
    }

    public function testAStreamIsReproducibleFromTheSeedAndIndependentOfOtherStreams(): void
    {
        $draw = static function (int $seed, string $stream): array {
            $hooks = new HookRegistry();
            $hooks->setSeed($seed);
            $random = $hooks->randomizer($stream);

            return [$random->getInt(0, PHP_INT_MAX), $random->getInt(0, PHP_INT_MAX), $random->getInt(0, 100)];
        };

        self::assertSame($draw(42, 'evilresp'), $draw(42, 'evilresp'));
        self::assertNotSame($draw(42, 'evilresp'), $draw(43, 'evilresp'));
        self::assertNotSame($draw(42, 'evilresp'), $draw(42, 'other'));

        /* Drawing from one stream leaves every other stream where it was. */
        $hooks = new HookRegistry();
        $hooks->setSeed(42);
        $hooks->randomizer('other')->getInt(0, PHP_INT_MAX);
        $random = $hooks->randomizer('evilresp');
        self::assertSame(
            $draw(42, 'evilresp'),
            [$random->getInt(0, PHP_INT_MAX), $random->getInt(0, PHP_INT_MAX), $random->getInt(0, 100)],
        );
    }

    public function testAStreamIsMemoizedUntilTheRegistryIsReseeded(): void
    {
        $hooks = new HookRegistry();
        $hooks->setSeed(9);
        $first = $hooks->randomizer('s')->getInt(0, PHP_INT_MAX);
        $second = $hooks->randomizer('s')->getInt(0, PHP_INT_MAX);

        $hooks->setSeed(9);
        self::assertSame(9, $hooks->seed());
        self::assertSame($first, $hooks->randomizer('s')->getInt(0, PHP_INT_MAX));
        self::assertSame($second, $hooks->randomizer('s')->getInt(0, PHP_INT_MAX));

        $hooks->setSeed(10);
        self::assertNotSame($first, $hooks->randomizer('s')->getInt(0, PHP_INT_MAX));
    }

    public function testARunSeedsTheRegistryBeforePostConstructorHooksFire(): void
    {
        $observe = static function (int $seed): array {
            $seen = [];
            $hooks = new HookRegistry();
            $hooks->onPostConstructor('draw', function (ClientEvent $event) use ($hooks, &$seen): void {
                $seen[] = [$hooks->seed(), $hooks->randomizer('test')->getInt(0, PHP_INT_MAX)];
            });
            (new Fuzzer($hooks))->run(
                [new \Redis()],
                new RunConfiguration(maxSteps: 2, seed: $seed, commands: ['isconnected'], includeLocal: true),
            );

            return $seen;
        };

        $first = $observe(31337);
        self::assertCount(1, $first);
        self::assertSame(31337, $first[0][0]);
        self::assertSame($first, $observe(31337));
        self::assertNotSame($first, $observe(31338));
    }

    public function testDrawingFromAHookStreamDoesNotChangeTheCommandStream(): void
    {
        $arguments = static function (bool $draw): array {
            $seen = [];
            $hooks = new HookRegistry();
            $hooks->onPreCommand('record', function (PendingInvocation $invocation) use ($hooks, $draw, &$seen): void {
                if ($draw) {
                    $hooks->randomizer('noise')->getInt(0, PHP_INT_MAX);
                }
                $seen[] = [$invocation->method, $invocation->arguments];
            });
            (new Fuzzer($hooks))->run(
                [new \Redis()],
                new RunConfiguration(maxSteps: 12, seed: 5, commands: ['_prefix', '_serialize'], includeLocal: true),
            );

            return $seen;
        };

        $plain = $arguments(false);
        self::assertCount(12, $plain);
        self::assertSame($plain, $arguments(true));
    }
}
