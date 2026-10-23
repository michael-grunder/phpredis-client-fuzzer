<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CommandTemplate;
use Mgrunder\PhpredisCommandFuzzer\Harness\TargetCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessTargetCheckTest extends TestCase
{
    /** @return iterable<string, array{list<string>, array{auth?: list<string>}}> */
    public static function credentials(): iterable
    {
        yield 'no auth' => [[], []];
        yield 'password' => [['--password=secret'], ['auth' => ['secret']]];
        yield 'ACL' => [
            ['--username', 'user', '--password', 'secret'],
            ['auth' => ['user', 'secret']],
        ];
    }

    /** @param list<string> $arguments
     *  @param array{auth?: list<string>} $context
     */
    #[DataProvider('credentials')]
    public function testChecksEveryPortWithBoundedTimeoutsAndWorkerCredentials(array $arguments, array $context): void
    {
        $probe = $this->createMock(\Redis::class);
        $ports = [];
        $probe->expects(self::exactly(2))->method('connect')->with(
            'redis.example', self::callback(static function (int $port) use (&$ports): bool {
                $ports[] = $port;
                return true;
            }), 1.0, null, 0, 1.0, $context,
        )->willReturn(true);
        $probe->expects(self::exactly(2))->method('ping')->willReturn(true, 'PONG');
        $probe->expects(self::exactly(2))->method('close')->willReturn(true);

        mt_srand(123);
        $expectedRandom = mt_rand();
        mt_srand(123);
        (new TargetCheck($probe))->check(new CommandTemplate([
            'fuzzer.php', '--port={port}', '--host=unused', '--host', 'redis.example', ...$arguments,
        ]), [6379, 7000]);

        self::assertSame([6379, 7000], $ports);
        self::assertSame($expectedRandom, mt_rand());
    }

    public function testReportsEveryFailureIncludingTheEndpointAndReason(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::exactly(2))->method('connect')->with(
            '127.0.0.1', self::anything(), 1.0, null, 0, 1.0, [],
        )->willReturnCallback(static function (string $host, int $port): bool {
            if ($port === 6379) {
                throw new \RedisException('Connection refused');
            }
            return false;
        });
        $probe->expects(self::never())->method('ping');
        $probe->expects(self::never())->method('close');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("127.0.0.1:6379: Connection refused\n  127.0.0.1:7000: connection failed");
        (new TargetCheck($probe))->check(new CommandTemplate(['fuzzer.php', '--port', '{port}']), [6379, 7000]);
    }

    public function testPingErrorClosesTheConnectionAndFailsStartup(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->willReturn(true);
        $probe->expects(self::once())->method('ping')->willThrowException(new \RedisException('read error'));
        $probe->expects(self::once())->method('close')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1:7000: read error');
        (new TargetCheck($probe))->check(new CommandTemplate(['fuzzer.php', '--port={port}']), [7000]);
    }

    public function testAnOpenConnectionWithoutPongIsNotEnough(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->willReturn(true);
        $probe->expects(self::once())->method('ping')->willReturn(false);
        $probe->method('getLastError')->willReturn('NOAUTH Authentication required.', null);
        $probe->expects(self::once())->method('clearLastError')->willReturn(true);
        $probe->expects(self::once())->method('close')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1:6379: NOAUTH Authentication required.');
        (new TargetCheck($probe))->check(new CommandTemplate(['fuzzer.php', '--port={port}']), [6379]);
    }

    public function testUnusedPortPoolsDoNotProbeUnrelatedServers(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::never())->method('connect');
        (new TargetCheck($probe))->check(new CommandTemplate(['fuzzer.php']), [6379]);
    }
}
