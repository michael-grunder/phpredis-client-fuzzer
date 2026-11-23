<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\RunConfiguration;
use Mgrunder\RedisClientTester\Tester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

trait RecordsUnknownCommand
{
    /** @var list<list<mixed>> */
    public array $calls = [];
    private ?string $lastError = null;

    public function __construct()
    {
    }

    /** @param mixed ...$args */
    public function rawCommand(mixed ...$args): mixed
    {
        if (in_array('command', $args, true)) {
            return [['get']];
        }
        $this->calls[] = array_values($args);
        $this->lastError = 'ERR unknown command';

        return false;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }

    public function getOption(int $option): mixed
    {
        return 0;
    }
}

final class RawCommandTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function clients(): iterable
    {
        foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('clients')]
    public function testUnknownCommandRunsWithoutRawOptions(string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped($class . ' is not loaded');
        }

        $client = match ($class) {
            Redis::class => new class extends Redis {
                use RecordsUnknownCommand;
            },
            RedisCluster::class => new class extends RedisCluster {
                use RecordsUnknownCommand;
            },
            Relay::class => new class extends Relay {
                use RecordsUnknownCommand;
            },
            Cluster::class => new class extends Cluster {
                use RecordsUnknownCommand;
            },
            default => self::fail('Unknown client ' . $class),
        };
        $configuration = new RunConfiguration(
            commands: ['rawcommand'], maxSteps: 3, seed: 42,
        );
        $result = (new Tester())->run([$client], $configuration);

        self::assertSame(['rawcommand'], $result->selectedCommands);
        self::assertCount(3, $client->calls);
        foreach ($client->calls as $args) {
            if ($client instanceof RedisCluster || $client instanceof Cluster) {
                $route = array_shift($args);
                self::assertIsString($route);
                self::assertMatchesRegularExpression(
                    '/^redis-client-tester:\\{[0-9]+}:route$/', $route,
                );
            }
            self::assertSame(['REDIS-CLIENT-TESTER-NO-SUCH-COMMAND'], $args);
        }
        foreach ($result->outcomes as $outcome) {
            self::assertSame('normal', $outcome->operation);
            self::assertSame(['ERR unknown command'], $outcome->redisErrors);
            self::assertSame([], $outcome->warnings);
            self::assertNull($outcome->exception);
        }
        self::assertSame([], $result->problematicCommands);

        $calls = $client->calls;
        $client->calls = [];
        (new Tester())->run([$client], $configuration);
        self::assertSame($calls, $client->calls);
    }
}
