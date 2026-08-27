<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\WarningCollector;
use PHPUnit\Framework\TestCase;

final class WarningCollectorTest extends TestCase
{
    public function testWarningsAreAggregatedAndAttributedToTheCurrentContext(): void
    {
        $collector = new WarningCollector();
        $errorReporting = error_reporting(E_ALL);
        try {
            $collector->reset();
            $collector->setContext('get');
            $collector->handle(E_USER_WARNING, 'first');
            $collector->handle(E_USER_WARNING, 'first');
            $collector->setContext('set');
            $collector->handle(E_WARNING, 'second');
            $collector->setContext(null);
            $collector->handle(E_WARNING, 'outside');

            self::assertSame(4, $collector->occurrenceCount());
            self::assertCount(3, $collector->warnings());
            self::assertSame(2, array_sum($collector->warningsByContext()['get']));
            self::assertSame(1, array_sum($collector->warningsByContext()['set']));
            self::assertCount(2, $collector->warningsByContext());
        } finally {
            error_reporting($errorReporting);
            $collector->restore();
        }
    }

    public function testMatchingWarningIsCaseInsensitive(): void
    {
        $collector = new WarningCollector();
        $errorReporting = error_reporting(E_ALL);
        try {
            $collector->reset();
            $collector->handle(E_USER_WARNING, 'Connection RESET by peer');

            self::assertStringContainsString(
                'Connection RESET by peer',
                $collector->matchingWarning('connection reset') ?? '',
            );
            self::assertNull($collector->matchingWarning('timed out'));
        } finally {
            error_reporting($errorReporting);
            $collector->restore();
        }
    }
}
