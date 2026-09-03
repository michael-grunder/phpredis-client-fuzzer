<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\ClusterNodesParser;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\CounterTracker;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Node;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\NodeRole;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Reading;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Sample;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Sampler;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Tui;
use PHPUnit\Framework\TestCase;

final class CommandStatsTest extends TestCase
{
    public function testItParsesCommandCalls(): void
    {
        self::assertSame([
            'get' => 123,
            'config|get' => 7,
        ], Sampler::calls([
            'cmdstat_get' => 'calls=123,usec=20,usec_per_call=0.16',
            'cmdstat_config|get' => 'calls=7,usec=2',
            'cmdstat_bad' => 'usec=2',
            'redis_version' => '8.0.0',
        ]));
    }

    public function testItParsesHealthyPrimaryAndReplicaNodes(): void
    {
        $reply = <<<'NODES'
aaaaaaaa 127.0.0.1:7000@17000 myself,master - 0 0 1 connected 0-8191
bbbbbbbb 127.0.0.1:7001@17001,redis-b replica aaaaaaaa 0 0 2 connected
cccccccc 127.0.0.1:7002@17002 master,fail - 0 0 3 disconnected 8192-16383
NODES;
        $nodes = (new ClusterNodesParser())->parse($reply);

        self::assertCount(2, $nodes);
        self::assertSame('127.0.0.1:7000', $nodes[0]->key());
        self::assertSame(NodeRole::Primary, $nodes[0]->role);
        self::assertSame(NodeRole::Replica, $nodes[1]->role);
    }

    public function testItTracksDeltasByRoleAndSurvivesCounterReset(): void
    {
        $primary = new Node('127.0.0.1', 7000, NodeRole::Primary);
        $replica = new Node('127.0.0.1', 7001, NodeRole::Replica);
        $tracker = new CounterTracker();
        $tracker->update(new Sample([
            new Reading($primary, ['get' => 100, 'set' => 20]),
            new Reading($replica, ['get' => 50]),
        ]));
        self::assertSame([], $tracker->totals());

        $tracker->update(new Sample([
            new Reading($primary, ['get' => 110, 'set' => 22]),
            new Reading($replica, ['get' => 57]),
        ]));
        $tracker->update(new Sample([
            new Reading($primary, ['get' => 3, 'set' => 1]),
            new Reading($replica, ['get' => 60]),
        ]));

        self::assertSame([
            ['command' => 'get', 'primary' => 13, 'replica' => 10],
            ['command' => 'set', 'primary' => 3, 'replica' => 0],
        ], $tracker->totals());
    }

    public function testLayoutAddsGroupsAsTheTerminalWidens(): void
    {
        self::assertSame(1, Tui::groupsForWidth(40, true));
        self::assertSame(2, Tui::groupsForWidth(100, true));
        self::assertSame(4, Tui::groupsForWidth(122, false));
    }

    public function testAClusterNodeDiscoveredLaterGetsItsOwnBaseline(): void
    {
        $first = new Node('127.0.0.1', 7000, NodeRole::Primary);
        $later = new Node('127.0.0.1', 7001, NodeRole::Replica);
        $tracker = new CounterTracker();
        $tracker->update(new Sample([new Reading($first, ['get' => 10])]));
        $tracker->update(new Sample([
            new Reading($first, ['get' => 11]),
            new Reading($later, ['get' => 500]),
        ]));

        self::assertSame([
            ['command' => 'get', 'primary' => 1, 'replica' => 0],
        ], $tracker->totals());
    }

    public function testSamplerReturnsAnErrorAndReconnectsAfterAnOutage(): void
    {
        $client = new class extends \Redis {
            public bool $available = false;

            public int $calls = 10;

            public int $closes = 0;

            /** @return array<string, string>|false */
            public function info(string ...$sections): array|false
            {
                return $this->available
                    ? ['cmdstat_get' => "calls={$this->calls},usec=1"]
                    : false;
            }

            public function close(): bool
            {
                $this->closes++;

                return true;
            }
        };
        $connections = 0;
        $sampler = new Sampler(
            ClientType::Redis,
            '127.0.0.1',
            6379,
            ['127.0.0.1:6379'],
            1.0,
            1.0,
            null,
            clientProvider: static function (ClientConfiguration $_configuration) use ($client, &$connections): object {
                $connections++;

                return $client;
            },
        );

        $down = $sampler->sample();
        self::assertSame([], $down->readings);
        self::assertCount(1, $down->errors);
        self::assertSame(1, $client->closes);

        $client->available = true;
        $baseline = $sampler->sample();
        self::assertCount(1, $baseline->readings);
        self::assertSame(['get' => 10], $baseline->readings[0]->calls);
        self::assertSame(2, $connections);

        $client->calls = 14;
        $tracker = new CounterTracker();
        $tracker->update($baseline);
        $tracker->update($sampler->sample());
        self::assertSame([
            ['command' => 'get', 'primary' => 4, 'replica' => 0],
        ], $tracker->totals());
    }
}
