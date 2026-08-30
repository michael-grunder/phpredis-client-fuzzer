<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
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
