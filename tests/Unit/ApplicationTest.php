<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\Application;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testHelpDocumentsInvocationModeWithoutConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            '--invocation-mode=MODE',
            self::contents($output),
        );
        self::assertStringContainsString('--saturate-chance=N', self::contents($output));
        self::assertStringContainsString('--saturate-steps=N', self::contents($output));
        self::assertStringContainsString('--saturate-target=TARGET', self::contents($output));
        self::assertStringContainsString('--saturate-mode=MODE', self::contents($output));
        self::assertStringContainsString('--raw-chaos', self::contents($output));
        self::assertStringContainsString('--hook=FILE', self::contents($output));
        self::assertStringContainsString('(default: strict)', self::contents($output));
        self::assertSame('', self::contents($error));
    }

    public function testHelpUsesTheEntrypointInvocationDefault(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application(
            $output,
            $error,
            InvocationMode::Coercive,
        ))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('(default: coercive)', self::contents($output));
        self::assertSame('', self::contents($error));
    }

    public function testUnknownInvocationModeIsRejectedBeforeConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--invocation-mode=weak',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            'Unknown invocation mode: weak',
            self::contents($error),
        );
    }

    public function testUnknownSaturationModeIsRejectedBeforeConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--saturate-mode=synthetic',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            'Unknown saturation mode: synthetic',
            self::contents($error),
        );
    }

    public function testUnreadableHookIsRejectedBeforeConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--hook=/definitely/missing/phpredis-fuzzer-hook.php',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            'Hook file is not readable',
            self::contents($error),
        );
    }

    public function testInvalidSaturationPercentageIsRejectedBeforeConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--saturate-target=100.1%',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            '--saturate-target percentage must be greater than 0 and at most 100',
            self::contents($error),
        );
    }

    public function testPercentageSaturationTargetRequiresRelayBeforeConnecting(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--client=redis',
            '--saturate-target=95.2%',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            'A percentage --saturate-target requires a Relay client',
            self::contents($error),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function phpRedisClients(): iterable
    {
        yield 'standalone' => ['redis'];
        yield 'cluster' => ['redis-cluster'];
        yield 'both' => ['redis,redis-cluster'];
    }

    #[DataProvider('phpRedisClients')]
    public function testRelayOptionsWarnAndAreIgnoredForPhpRedisClients(string $clients): void
    {
        $output = self::stream();
        $error = self::stream();
        $status = (new Application($output, $error))->run([
            "--client={$clients}",
            '--relay-failover=unsupported',
            '--relay-distribute=unsupported',
            '--relay-node-read-timeout=unsupported',
            '--relay-multikey-reordering=unsupported',
            '--port=0',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertSame(
            "Warning: --relay-failover doesn't apply to PhpRedis, ignoring\n"
            . "Warning: --relay-distribute doesn't apply to PhpRedis, ignoring\n"
            . "Warning: --relay-node-read-timeout doesn't apply to PhpRedis, ignoring\n"
            . "Warning: --relay-multikey-reordering doesn't apply to PhpRedis, ignoring\n"
            . "phpredis-fuzz: Port must be between 1 and 65535\n",
            self::contents($error),
        );
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
