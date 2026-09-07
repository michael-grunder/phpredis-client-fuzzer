<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
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
            | Command::FLUSH | Command::CRASH | Command::RAW | Command::STATEFUL;

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
