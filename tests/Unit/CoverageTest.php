<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\Cli\CoverageApplication;
use Mgrunder\PhpredisCommandFuzzer\Coverage\CoverageAnalyzer;
use Mgrunder\PhpredisCommandFuzzer\Coverage\IgnoreList;
use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    public function testServerCommandsNormalisesNamesAndFoldsSubcommands(): void
    {
        $commands = ServerCommands::fromNames(['SET', ' get ', 'config|get', 'config|set', '']);

        self::assertSame(['config', 'get', 'set'], $commands->names);
        self::assertSame(3, $commands->count());
        self::assertTrue($commands->has('SET'));
        self::assertFalse($commands->has('del'));
    }

    public function testServerCommandsAcceptsCommandRowsAndFlatLists(): void
    {
        $rows = ServerCommands::fromReply([['get', 2, ['readonly']], ['set', -3, ['write']]]);
        $flat = ServerCommands::fromReply(['set', 'get']);

        self::assertSame(['get', 'set'], $rows->names);
        self::assertSame($rows->names, $flat->names);
    }

    public function testServerCommandsRoutesClusterRawCommandThroughAKey(): void
    {
        $client = new class extends \RedisCluster {
            /** @var list<mixed> */
            public array $arguments = [];

            public function __construct()
            {
            }

            /** @param array<mixed>|string $keyOrAddress */
            public function rawCommand(array|string $keyOrAddress, string $command, mixed ...$args): mixed
            {
                $this->arguments = [$keyOrAddress, $command];
                foreach ($args as $arg) {
                    $this->arguments[] = $arg;
                }

                return [['get']];
            }
        };

        self::assertSame(['get'], ServerCommands::fromClient($client)->names);
        self::assertSame(
            ['phpredis-command-fuzzer:command-table', 'command'],
            $client->arguments,
        );
    }

    public function testServerCommandsRejectsMalformedReplies(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ServerCommands::fromReply([['get'], [42]]);
    }

    public function testIgnoreListSkipsCommentsAndMatchesGlobs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'coverage-ignore');
        self::assertIsString($path);
        file_put_contents($path, "# a comment\n\ncluster*\nSHUTDOWN\n");

        try {
            $ignore = IgnoreList::fromFile($path);
        } finally {
            unlink($path);
        }

        self::assertSame(['cluster*', 'shutdown'], $ignore->patterns());
        self::assertSame('cluster*', $ignore->match('CLUSTER'));
        self::assertTrue($ignore->matches('shutdown'));
        self::assertNull($ignore->match('get'));
    }

    public function testIgnoreListWithAppendsWithoutDuplicating(): void
    {
        $ignore = (new IgnoreList(['save']))->with(['SAVE', 'bgsave']);

        self::assertSame(['save', 'bgsave'], $ignore->patterns());
    }

    public function testDefaultIgnoreFileIsReadableAndCoversAdminNoise(): void
    {
        $ignore = IgnoreList::defaults();

        self::assertNotSame([], $ignore->patterns());
        self::assertTrue($ignore->matches('shutdown'));
        self::assertTrue($ignore->matches('subscribe'));
        self::assertTrue($ignore->matches('replconf'));
        self::assertFalse($ignore->matches('publish'));
        self::assertFalse($ignore->matches('get'));
    }

    public function testAnalyserSplitsUncoveredCommandsByClientMethod(): void
    {
        $report = (new CoverageAnalyzer())->analyse(
            ServerCommands::fromNames(['get', 'set', 'sort', 'xsetid', 'shutdown']),
            ClientType::Redis,
            null,
            new IgnoreList(['shutdown']),
        );

        self::assertSame(['get', 'set', 'sort'], $report->covered);

        /* SORT is now covered by the command catalog. */
        self::assertSame([], $report->missing);

        /* PhpRedis has no xSetId(), so it cannot be fuzzed at all. */
        self::assertSame(['xsetid'], $report->unsupported);

        self::assertSame(['shutdown' => 'shutdown'], $report->ignored);
        self::assertSame(5, $report->serverCommands);
        self::assertSame(4, $report->considered());
        self::assertSame(0.75, $report->ratio());
    }

    public function testIgnorePatternsNeverHideCoveredCommands(): void
    {
        $report = (new CoverageAnalyzer())->analyse(
            ServerCommands::fromNames(['get', 'sort']),
            ClientType::Redis,
            null,
            new IgnoreList(['*']),
        );

        self::assertSame(['get', 'sort'], $report->covered);
        self::assertSame([], $report->missing);
        self::assertSame([], $report->ignored);
    }

    public function testCatalogCommandsAbsentFromTheServerAreClassified(): void
    {
        $report = (new CoverageAnalyzer())->analyse(
            ServerCommands::fromNames(['get']),
            ClientType::Redis,
        );

        /* Client-side API that never reaches a server, plus every catalog
         * command PhpRedis exposes but this tiny command table omits. */
        self::assertContains('connect', $report->clientApi);
        self::assertContains('_serialize', $report->clientApi);
        self::assertContains('set', $report->clientApi);

        self::assertSame([], array_intersect($report->clientApi, $report->unmatched));
        self::assertSame(
            $report->catalogCommands - count($report->covered),
            count($report->clientApi) + count($report->unmatched),
        );

        /* Whatever lands in unmatched is a catalog entry PhpRedis cannot even
         * call, such as a Relay extension checked against PhpRedis. */
        foreach ($report->unmatched as $name) {
            self::assertFalse(method_exists(\Redis::class, $name), $name);
        }
    }

    public function testReportSerialisesToJson(): void
    {
        $report = (new CoverageAnalyzer())->analyse(
            ServerCommands::fromNames(['get', 'sort']),
            ClientType::Redis,
        );

        $decoded = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true);

        self::assertIsArray($decoded);
        self::assertSame('redis', $decoded['client']);
        self::assertSame('Redis', $decoded['client_class']);
        self::assertSame([], $decoded['missing']);
        self::assertSame(1, $decoded['ratio']);
    }

    public function testCliReportsCoverageFromAFileWithoutARedisServer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'coverage-commands');
        self::assertIsString($path);
        file_put_contents($path, "get\nset\nsort\nshutdown\n");

        $stream = self::stream();
        try {
            $status = (new CoverageApplication($stream))->run([
                '--client=redis',
                "--commands-file={$path}",
                '--quiet',
            ]);
        } finally {
            unlink($path);
        }

        self::assertSame(0, $status);
        self::assertSame('', self::contents($stream));
    }

    public function testCliRejectsUnknownOptions(): void
    {
        $error = self::stream();
        $status = (new CoverageApplication(self::stream(), $error))->run(['--nope']);

        self::assertSame(1, $status);
        self::assertStringContainsString('Unknown option: --nope', self::contents($error));
    }

    public function testCliHelpDoesNotConnect(): void
    {
        $stream = self::stream();
        $status = (new CoverageApplication($stream))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('phpredis-coverage', self::contents($stream));
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

        return (string) stream_get_contents($stream);
    }
}
