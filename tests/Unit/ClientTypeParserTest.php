<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\ClientTypeParser;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTypeParserTest extends TestCase
{
    public function testParsesTypesWithOptionalCounts(): void
    {
        self::assertSame(
            [
                ClientType::Redis,
                ClientType::Redis,
                ClientType::Relay,
                ClientType::RelayCluster,
                ClientType::RelayCluster,
                ClientType::RelayCluster,
            ],
            ClientTypeParser::parse('redis:2, relay, relay-cluster:3'),
        );
    }

    public function testRepeatedTypesAccumulateInstances(): void
    {
        self::assertSame(
            [ClientType::Redis, ClientType::Redis, ClientType::Redis],
            ClientTypeParser::parse('redis:2,redis'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSpecifications(): iterable
    {
        yield 'empty' => ['', '--client cannot be empty'];
        yield 'unknown type' => ['predis:2', 'Unknown client type: predis'];
        yield 'missing type' => [':2', 'Invalid client specification: :2'];
        yield 'missing count' => ['redis:', 'Client count must be a positive integer: redis:'];
        yield 'zero count' => ['redis:0', 'Client count must be a positive integer: redis:0'];
        yield 'negative count' => ['redis:-1', 'Client count must be a positive integer: redis:-1'];
        yield 'decimal count' => ['redis:1.5', 'Client count must be a positive integer: redis:1.5'];
        yield 'multiple separators' => ['redis:1:2', 'Invalid client specification: redis:1:2'];
        yield 'excessive count' => [
            'redis:' . (ClientTypeParser::MAX_CLIENTS + 1),
            '--client may create at most ' . ClientTypeParser::MAX_CLIENTS . ' clients',
        ];
    }

    #[DataProvider('invalidSpecifications')]
    public function testRejectsInvalidSpecifications(string $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ClientTypeParser::parse($value);
    }
}
