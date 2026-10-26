<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\ClientTopologySelector;
use Mgrunder\RedisClientTester\Cli\ClientTypeParser;
use Mgrunder\RedisClientTester\ClientConfiguration;
use Mgrunder\RedisClientTester\ClientType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTopologySelectorTest extends TestCase
{
    public function testTopologyProbeUsesTlsAndCertificateOptions(): void
    {
        $tls = new \Mgrunder\RedisClientTester\TlsConfiguration(true, ['cafile' => '/tmp/ca.pem']);
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->with(
            'tls://localhost', 6380, 1.0, null, 0, 1.0,
            ['auth' => ['secret'], 'stream' => ['cafile' => '/tmp/ca.pem']],
        )->willReturn(true);
        $probe->method('info')->willReturn(['cluster_enabled' => 0]);
        $probe->expects(self::once())->method('close')->willReturn(true);
        $standalone = new ClientConfiguration(host: 'localhost', port: 6380, auth: 'secret', tls: $tls);
        self::assertSame([$standalone], (new ClientTopologySelector($probe))->select([
            $standalone, new ClientConfiguration(type: ClientType::RedisCluster, tls: $tls),
        ]));
    }

    /** @return iterable<string, array{int|string, list<int>}> */
    public static function topologies(): iterable
    {
        yield 'standalone integer' => [0, [0, 3, 4]];
        yield 'cluster integer' => [1, [1, 2, 5]];
        yield 'standalone string' => ['0', [0, 3, 4]];
        yield 'cluster string' => ['1', [1, 2, 5]];
    }

    /** @param list<int> $positions */
    #[DataProvider('topologies')]
    public function testSelectsAllMatchesPreservingOrderCountsAndSettings(int|string $enabled, array $positions): void
    {
        $configurations = self::configurations('redis,relay-cluster:2,relay:2,redis-cluster');
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->with(
            'target.example', 7001, 2.5, null, 0, 3.5, ['auth' => ['user', 'secret']],
        )->willReturn(true);
        $probe->expects(self::once())->method('info')->with('cluster')
            ->willReturn(['cluster_enabled' => $enabled]);
        $probe->expects(self::once())->method('close')->willReturn(true);

        mt_srand(123456);
        $expectedRandom = mt_rand();
        mt_srand(123456);
        $selected = (new ClientTopologySelector($probe))->select($configurations);

        self::assertSame(array_map(
            static fn (int $position): ClientConfiguration => $configurations[$position],
            $positions,
        ), $selected);
        self::assertSame($expectedRandom, mt_rand());
    }

    /** @return iterable<string, array{string}> */
    public static function singleTopologies(): iterable
    {
        yield 'standalone only' => ['redis:2,relay'];
        yield 'cluster only' => ['relay-cluster:2,redis-cluster'];
        yield 'one standalone' => ['relay'];
        yield 'one cluster' => ['redis-cluster'];
    }

    #[DataProvider('singleTopologies')]
    public function testSingleTopologyListsAreUnchangedWithoutProbing(string $clients): void
    {
        $configurations = self::configurations($clients);
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::never())->method('connect');
        $probe->expects(self::never())->method('info');
        $probe->expects(self::never())->method('close');

        self::assertSame($configurations, (new ClientTopologySelector($probe))->select($configurations));
    }

    /** @return iterable<string, array{array<string, int|string>|false}> */
    public static function invalidReplies(): iterable
    {
        yield 'error reply' => [false];
        yield 'missing field' => [[]];
        yield 'invalid number' => [['cluster_enabled' => 2]];
        yield 'invalid string' => [['cluster_enabled' => 'unknown']];
    }

    /** @param array<string, int|string>|false $reply */
    #[DataProvider('invalidReplies')]
    public function testDoesNotGuessWhenInfoIsUnavailableOrInvalid(array|false $reply): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->willReturn(true);
        $probe->expects(self::once())->method('info')->willReturn($reply);
        $probe->expects(self::once())->method('close')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--pick-client requires INFO cluster');
        (new ClientTopologySelector($probe))->select(self::configurations('redis,redis-cluster'));
    }

    public function testClosesProbeAndPreservesExceptions(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->willReturn(true);
        $probe->expects(self::once())->method('info')
            ->willThrowException(new \RedisException('NOPERM info denied'));
        $probe->expects(self::once())->method('close')->willReturn(true);

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('NOPERM info denied');
        (new ClientTopologySelector($probe))->select(self::configurations('relay,relay-cluster'));
    }

    public function testConnectionFailureDoesNotAttemptInfo(): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')->willReturn(false);
        $probe->expects(self::never())->method('info');
        $probe->expects(self::never())->method('close');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--pick-client could not connect');
        (new ClientTopologySelector($probe))->select(self::configurations('relay,relay-cluster'));
    }

    /** @return iterable<string, array{string|array{string, string}|null, array{auth?: array{string, 1?: string}}}> */
    public static function credentials(): iterable
    {
        yield 'no auth' => [null, []];
        yield 'password' => ['secret', ['auth' => ['secret']]];
        yield 'ACL' => [['user', 'secret'], ['auth' => ['user', 'secret']]];
    }

    /**
     * @param string|array{string, string}|null $auth
     * @param array{auth?: array{string, 1?: string}} $context
     */
    #[DataProvider('credentials')]
    public function testProbeUsesConfiguredCredentials(string|array|null $auth, array $context): void
    {
        $probe = $this->createMock(\Redis::class);
        $probe->expects(self::once())->method('connect')
            ->with('127.0.0.1', 6379, 1.0, null, 0, 1.0, $context)->willReturn(true);
        $probe->expects(self::once())->method('info')->willReturn(['cluster_enabled' => 0]);
        $probe->expects(self::once())->method('close')->willReturn(true);
        $standalone = new ClientConfiguration(auth: $auth);

        self::assertSame([$standalone], (new ClientTopologySelector($probe))->select([
            $standalone,
            new ClientConfiguration(type: ClientType::RedisCluster, auth: $auth),
        ]));
    }

    /** @return non-empty-list<ClientConfiguration> */
    private static function configurations(string $clients): array
    {
        return array_map(
            static fn (ClientType $type): ClientConfiguration => new ClientConfiguration(
                type: $type,
                host: 'target.example',
                port: 7001,
                seeds: ['different-seed.example:7002'],
                timeout: 2.5,
                readTimeout: 3.5,
                auth: ['user', 'secret'],
                prefix: 'test:',
            ),
            ClientTypeParser::parse($clients),
        );
    }
}
