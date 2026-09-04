<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\Application;
use Mgrunder\PhpredisCommandFuzzer\Cli\ExitCode;
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
        self::assertStringContainsString('--include=CATEGORY,...', self::contents($output));
        self::assertStringNotContainsString('--include-admin', self::contents($output));
        self::assertStringNotContainsString('--include-local', self::contents($output));
        self::assertStringNotContainsString('--include-flush', self::contents($output));
        self::assertStringNotContainsString('--include-stateful', self::contents($output));
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

        self::assertSame(ExitCode::STARTUP, $status);
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

        self::assertSame(ExitCode::STARTUP, $status);
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

        self::assertSame(ExitCode::STARTUP, $status);
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

        self::assertSame(ExitCode::STARTUP, $status);
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

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString(
            'A percentage --saturate-target requires a Relay client',
            self::contents($error),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function clientsWithoutRelayCluster(): iterable
    {
        yield 'PhpRedis standalone' => ['redis'];
        yield 'PhpRedis cluster' => ['redis-cluster'];
        yield 'PhpRedis standalone and cluster' => ['redis,redis-cluster'];
        yield 'Relay standalone' => ['relay'];
        yield 'multiple Relay standalone' => ['relay:2'];
        yield 'PhpRedis and Relay standalone' => ['redis,relay'];
    }

    #[DataProvider('clientsWithoutRelayCluster')]
    public function testRelayClusterOptionsWarnAndAreIgnoredWithoutRelayCluster(string $clients): void
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

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertSame('', self::contents($output));
        self::assertSame(
            "Warning: --relay-failover only applies to --client=relay-cluster, ignoring\n"
            . "Warning: --relay-distribute only applies to --client=relay-cluster, ignoring\n"
            . "Warning: --relay-node-read-timeout only applies to --client=relay-cluster, ignoring\n"
            . "Warning: --relay-multikey-reordering only applies to --client=relay-cluster, ignoring\n"
            . "phpredis-fuzz: Port must be between 1 and 65535\n",
            self::contents($error),
        );
    }

    public function testRelayClusterOptionsRemainValidatedForRelayCluster(): void
    {
        $output = self::stream();
        $error = self::stream();
        $status = (new Application($output, $error))->run([
            '--client=relay-cluster',
            '--relay-failover=unsupported',
            '--port=0',
        ]);

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertSame('', self::contents($output));
        self::assertSame(
            'phpredis-fuzz: Unknown failover mode "unsupported"; expected one of '
            . "none, primary, random_replica, replicas, all\n",
            self::contents($error),
        );
    }

    public function testUnknownOptionIsAStartupFailure(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run(['--stpes=10']);

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString('Unknown option: --stpes', self::contents($error));
    }

    public function testInvalidRunConfigurationIsAStartupFailure(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run(['--steps=0', '--seconds=0']);

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertStringContainsString(
            'At least one run limit must be greater than zero',
            self::contents($error),
        );
    }

    /**
     * A target that cannot be reached is a normal failure, not a startup one:
     * the command line was fine, so retrying it is not pointless.
     */
    public function testAnUnreachableTargetIsNotAStartupFailure(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new Application($output, $error))->run([
            '--client=redis',
            '--host=127.0.0.1',
            '--port=1',
            '--timeout=0.25',
            '--steps=1',
        ]);

        self::assertSame(ExitCode::FAILURE, $status);
        self::assertStringContainsString('phpredis-fuzz: ', self::contents($error));
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
