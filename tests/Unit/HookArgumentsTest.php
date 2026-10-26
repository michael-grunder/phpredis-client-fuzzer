<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\Application;
use Mgrunder\RedisClientTester\Cli\ExitCode;
use Mgrunder\RedisClientTester\Cli\Options;
use Mgrunder\RedisClientTester\Hooks\HookLoader;
use Mgrunder\RedisClientTester\Hooks\PendingInvocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HookArgumentsTest extends TestCase
{
    public function testFactoriesAndDirectConstructorsReceiveOnlyTheirArguments(): void
    {
        $values = ['configurable-hook.reason=a,b=c (d)', 'direct-hook.reason=another', 'direct-hook.empty='];
        $options = Options::parse(
            ['--hook-arg', $values[0], '--hook-arg=' . $values[1], '--hook-arg=' . $values[2]],
            ['hook-arg'], [], ['hook-arg'],
        );
        self::assertSame($values, $options->repeated('hook-arg', splitCommas: false));
        $arguments = HookLoader::parseArguments($options->repeated('hook-arg', splitCommas: false));
        $paths = array_map(static fn (string $name): string => dirname(__DIR__) . "/Fixtures/{$name}.php", array_keys($arguments));
        $hooks = HookLoader::load($paths, $arguments);
        self::assertSame($arguments['configurable-hook'], $hooks->metadata()['sources'][0]['arguments'] ?? null);
        self::assertSame($arguments['direct-hook'], $hooks->metadata()['sources'][1]['arguments'] ?? null);
        $call = new PendingInvocation('ping', new \Redis(), 'ping', []);
        self::assertSame('a,b=c (d)', $hooks->beforeInvocation($call)->reason);
        $direct = HookLoader::load([$paths[1]], ['direct-hook' => $arguments['direct-hook']]);
        self::assertSame('another', $direct->beforeInvocation($call)->reason);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'syntax' => [['--hook-arg=evilresp(mode:chaos)'], 'HOOK.KEY=VALUE'];
        yield 'empty key' => [['--hook-arg=evilresp.=5'], 'HOOK.KEY=VALUE'];
        yield 'missing hook' => [['--hook-arg=evilresp.mode=chaos'], 'exactly one --hook named evilresp'];
        yield 'duplicate' => [['--hook-arg=x.key=', '--hook-arg=x.key=2'], 'Duplicate --hook-arg'];
        yield 'empty' => [['--hook-arg='], 'HOOK.KEY=VALUE'];
        $hook = '--hook=' . dirname(__DIR__, 2) . '/hooks/evilresp.php';
        yield 'ambiguous' => [[$hook, $hook, '--hook-arg=evilresp.mode=chaos'], 'exactly one --hook'];
        yield 'hook validation' => [[$hook, '--hook-arg=evilresp.mode=chaos', '--hook-arg=evilresp.chaos_max=101'], 'chaos_max'];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsFailBeforeConnecting(array $arguments, string $message): void
    {
        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        self::assertIsResource($error);
        try {
            self::assertSame(ExitCode::STARTUP, (new Application($output, $error))->run($arguments));
            rewind($error);
            $text = stream_get_contents($error);
            self::assertIsString($text);
            self::assertStringContainsString($message, $text);
        } finally {
            fclose($output);
            fclose($error);
        }
    }
}
