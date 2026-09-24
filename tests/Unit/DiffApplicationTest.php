<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\DiffApplication;
use Mgrunder\PhpredisCommandFuzzer\Cli\ExitCode;
use PHPUnit\Framework\TestCase;

final class DiffApplicationTest extends TestCase
{
    public function testHelpAndCatalogNeedNoServer(): void
    {
        [$status, $output] = self::invoke(['--help']);
        self::assertSame(ExitCode::SUCCESS, $status);
        self::assertStringContainsString('--allow-flush', $output);

        [$status, $output] = self::invoke(['--list-commands']);
        self::assertSame(ExitCode::SUCCESS, $status);
        self::assertMatchesRegularExpression('/^hGetAll\s+@hash$/m', $output);
        self::assertMatchesRegularExpression('/^flushdb\s+@flush$/m', $output);
    }

    public function testRejectedCommandLinesAreStartupErrorsForTheHarness(): void
    {
        foreach ([['--bogus'], ['--steps=0'], ['--order=sideways'], ['--commands=@nope'], ['--relay-cache=maybe'], ['--wrongtype-chance=2']] as $arguments) {
            [$status, , $error] = self::invoke($arguments);
            self::assertSame(ExitCode::STARTUP, $status, implode(' ', $arguments) . ': ' . $error);
            self::assertStringStartsWith('phpredis-fuzz-diff: ', $error);
        }
    }

    public function testAnUnreachableServerIsAnOrdinaryFailure(): void
    {
        if (!class_exists('Relay\\Relay')) {
            self::markTestSkipped('Relay is not loaded');
        }

        [$status, , $error] = self::invoke(['--host=127.0.0.1', '--port=1', '--timeout=0.2']);
        self::assertSame(ExitCode::FAILURE, $status);
        self::assertStringContainsString('cannot connect', $error);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private static function invoke(array $arguments): array
    {
        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        self::assertIsResource($error);

        $status = (new DiffApplication($output, $error))->run($arguments);
        rewind($output);
        rewind($error);

        return [$status, (string) stream_get_contents($output), (string) stream_get_contents($error)];
    }
}
