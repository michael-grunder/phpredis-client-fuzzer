<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CommandTemplate;
use PHPUnit\Framework\TestCase;

final class HarnessCommandTemplateTest extends TestCase
{
    public function testReportsAndSubstitutesVariables(): void
    {
        $template = new CommandTemplate([
            'bin/phpredis-fuzz-coercive', '--steps', '{steps}', '--host=127.0.0.1', '--port={port}',
        ]);

        self::assertEqualsCanonicalizing(['steps', 'port'], $template->variables());
        self::assertTrue($template->uses('steps'));
        self::assertFalse($template->uses('seed'));

        self::assertSame(
            ['bin/phpredis-fuzz-coercive', '--steps', '2500', '--host=127.0.0.1', '--port=7003'],
            $template->render(['steps' => 2500, 'port' => 7003, 'seed' => 1, 'job' => 0, 'run' => 9]),
        );
    }

    public function testRejectsUnknownVariable(): void
    {
        $template = new CommandTemplate(['bin/phpredis-fuzz', '--keys={keys}']);

        $this->expectException(\InvalidArgumentException::class);
        $template->validate();
    }

    public function testDetectsExplicitOption(): void
    {
        $template = new CommandTemplate(['bin/phpredis-fuzz', '--seed=42', '--steps', '{steps}']);

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
