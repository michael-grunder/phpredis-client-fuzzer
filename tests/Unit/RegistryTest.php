<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testCommonCommandsIncludeEverydayOperationsAndExcludeSpecializedOnes(): void
    {
        $registry = (new CommandFilter())->apply(new Registry(), new RunConfiguration(
            commands: ['@common'], weights: ['@common' => 2.5],
        ));
        foreach (['get', 'set', 'mget', 'mset', 'hgetall', 'hmset', 'sadd', 'smembers', 'sismember', 'lpush', 'zadd', 'expire'] as $name) {
            self::assertNotNull($registry->get($name), $name);
            self::assertSame(2.5, $registry->get($name)->weight(), $name);
        }
        foreach (['increx', 'vadd', 'himport', 'getwithmeta', 'crash', 'blpop', 'brpop'] as $name) {
            self::assertNull($registry->get($name), $name);
        }
        $blocking = (new CommandFilter())->apply(new Registry(), new RunConfiguration(
            commands: ['@common'], commandGroups: [['@blocking']], includeBlocking: true,
        ));
        self::assertEqualsCanonicalizing(['blpop', 'brpop'], $blocking->names());
    }

    /** @return iterable<string, array{list<string>, list<list<string>>, list<string>}> */
    public static function groupedPatterns(): iterable
    {
        yield 'AND with OR' => [['@common'], [['@invalidating', '@cached'], ['get', 'set', 'ttl', 'getwithmeta']], ['get', 'set']];
        yield 'negatives win within a group' => [['get', 'set'], [['get', 'set', '-set']], ['get']];
        yield 'exclusion-only group' => [['get', 'set'], [['-get']], ['set']];
        yield 'earlier exclusion cannot be restored' => [['-get'], [['get', 'set']], ['set']];
        yield 'case and whitespace' => [[' @COMMON '], [[' +GET ', ' SET ', '-SeT']], ['get']];
        yield 'empty groups do not constrain' => [[], [[], ['get', 'set'], []], ['get', 'set']];
    }

    /**
     * @param list<string> $commands
     * @param list<list<string>> $groups
     * @param list<string> $expected
     */
    #[DataProvider('groupedPatterns')]
    public function testGroupsIntersect(array $commands, array $groups, array $expected): void
    {
        $configuration = new RunConfiguration(commands: $commands, commandGroups: $groups);
        $registry = (new CommandFilter())->apply(new Registry(), $configuration);
        self::assertEqualsCanonicalizing($expected, $registry->names());
        self::assertSame($groups, $configuration->jsonSerialize()['commandGroups']);
    }

    public function testDisjointGroupsLeaveNoCommands(): void
    {
        $this->expectException(\UnderflowException::class);
        $this->expectExceptionMessage('No commands remain after filtering');
        (new CommandFilter())->apply(new Registry(), new RunConfiguration(
            commands: ['get'], commandGroups: [['set']],
        ));
    }

    public function testUnknownFlagIsRejectedEvenAfterAnEmptyIntersection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown command flag: @typo');
        (new CommandFilter())->apply(new Registry(), new RunConfiguration(
            commands: ['no-such-command'], commandGroups: [['*', '@typo']],
        ));
    }

    public function testDiscoversTheMigratedCommandCatalog(): void
    {
        $registry = new Registry();

        self::assertGreaterThanOrEqual(230, count($registry));
        self::assertNotNull($registry->get('get'));
        self::assertNotNull($registry->get('xadd'));
        self::assertNotNull($registry->get('zunionstore'));
    }

    public function testDefaultFilterExcludesHighRiskCategories(): void
    {
        $registry = (new CommandFilter())->apply(new Registry(), new RunConfiguration());
        $excluded = Command::BLOCKING | Command::LOCAL | Command::ADMIN
            | Command::FLUSH | Command::CRASH | Command::RAW | Command::STATEFUL | Command::CONNECTION;

        foreach ($registry as $command) {
            self::assertSame(0, $command->flags() & $excluded, $command->name());
        }
    }

    public function testPatternsAndWeightsAreApplied(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(commands: ['get*', '-getex'], weights: ['get' => 4.5]),
        );

        $get = $registry->get('get');
        self::assertNotNull($get);
        self::assertNull($registry->get('getex'));
        self::assertSame(4.5, $get->weight());
        foreach ($registry->names() as $name) {
            self::assertStringStartsWith('get', $name);
        }
    }

    public function testConnectionCommandsCanBeSelectedAndWeightedWithoutLocal(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(
                commands: ['@connection'],
                weights: ['@connection' => 2.5],
                includeConnection: true,
            ),
        );

        self::assertEqualsCanonicalizing(['connect', 'close', 'pclose'], $registry->names());
        foreach ($registry as $command) {
            self::assertSame(Command::CONNECTION, $command->flags(), $command->name());
            self::assertSame(2.5, $command->weight(), $command->name());
        }
    }

    public function testLocalDoesNotEnableConnectionChangesEvenWhenNamed(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(commands: ['close', 'pclose', 'isconnected', 'setoption'], includeLocal: true),
        );

        self::assertEqualsCanonicalizing(['isconnected', 'setoption'], $registry->names());
    }

    public function testStatefulCommandsCanBeEnabledExplicitly(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(includeStateful: true),
        );

        self::assertNotNull($registry->get('multi'));
        self::assertNotNull($registry->get('discard'));
    }

    public function testCrashIsSelectableWithoutOtherCategories(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(includeCrashing: true, commands: ['crash']),
        );

        $crash = $registry->get('crash');
        self::assertNotNull($crash);
        self::assertSame(Command::CRASH, $crash->flags());
    }

    public function testRawChaosEnablesCommandsThatRequireRawExecution(): void
    {
        $registry = (new CommandFilter())->apply(
            new Registry(),
            new RunConfiguration(rawChaos: true),
        );

        self::assertNotNull($registry->get('append'));
        self::assertNotNull($registry->get('getrange'));
        self::assertNull($registry->get('flushall'));
    }

    public function testSafetyFlagsStillApplyToExplicitCommandNames(): void
    {
        $this->expectException(\UnderflowException::class);

        (new CommandFilter())->apply(new Registry(), new RunConfiguration(commands: ['flushall']));
    }
}
