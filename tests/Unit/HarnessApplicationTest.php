<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\HarnessApplication;
use PHPUnit\Framework\TestCase;

final class HarnessApplicationTest extends TestCase
{
    public function testMissingFuzzerScriptIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--runs', '1',
            '--',
            'bin/phpredis-fuz', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/phpredis-fuz',
            self::contents($error),
        );
    }

    public function testMissingScriptIsDetectedBehindAnExplicitPhpInterpreter(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--',
            'php', 'bin/phpredis-fuz',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/phpredis-fuz',
            self::contents($error),
        );
    }

    public function testMissingPhpIniIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--php-ini', '/nonexistent/phpredis-fuzz.ini',
            '--runs', '1',
            '--',
            'bin/phpredis-fuzz', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'php ini file not found: /nonexistent/phpredis-fuzz.ini',
            self::contents($error),
        );
    }

    public function testHelpDocumentsThePhpStartupOptions(): void
    {
        $output = self::stream();

        $status = (new HarnessApplication($output, self::stream()))->run(['--help']);

        self::assertSame(0, $status);
        $help = self::contents($output);
        self::assertStringContainsString('--php-args ARGS', $help);
        self::assertStringContainsString('--php-ini FILE', $help);
    }

    public function testHelpDocumentsTheStartupFailureExitCode(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('A run that exits 78 is a startup failure', self::contents($output));
        self::assertSame('', self::contents($error));
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
