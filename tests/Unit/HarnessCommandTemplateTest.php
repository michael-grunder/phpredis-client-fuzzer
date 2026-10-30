<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\CommandTemplate;
use PHPUnit\Framework\TestCase;

final class HarnessCommandTemplateTest extends TestCase
{
    public function testReportsAndSubstitutesVariables(): void
    {
        $template = new CommandTemplate([
            'bin/coercive', '--steps', '{steps}', '--host=127.0.0.1', '--port={port}',
        ]);

        self::assertEqualsCanonicalizing(['steps', 'port'], $template->variables());
        self::assertTrue($template->uses('steps'));
        self::assertFalse($template->uses('seed'));

        self::assertSame(
            ['bin/coercive', '--steps', '2500', '--host=127.0.0.1', '--port=7003'],
            $template->render(['steps' => 2500, 'port' => 7003, 'seed' => 1, 'job' => 0, 'run' => 9]),
        );
    }

    public function testRejectsUnknownVariable(): void
    {
        $template = new CommandTemplate(['bin/tester', '--keys={keys}']);

        $this->expectException(\InvalidArgumentException::class);
        $template->validate();
    }

    public function testDetectsExplicitOption(): void
    {
        $template = new CommandTemplate(['bin/tester', '--seed=42', '--steps', '{steps}']);

        self::assertTrue($template->hasOption('seed'));
        self::assertTrue($template->hasOption('steps'));
        self::assertFalse($template->hasOption('port'));
    }

    public function testEmptyTemplateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommandTemplate([]);
    }
}
