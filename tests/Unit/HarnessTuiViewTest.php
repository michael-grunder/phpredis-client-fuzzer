<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\TuiView;
use PhpTui\Term\EventProvider\ArrayEventProvider;
use PhpTui\Term\InformationProvider\AggregateInformationProvider;
use PhpTui\Term\Painter\AnsiPainter;
use PhpTui\Term\RawMode\TestRawMode;
use PhpTui\Term\Terminal;
use PhpTui\Term\Writer\StringWriter;
use PHPUnit\Framework\TestCase;

final class HarnessTuiViewTest extends TestCase
{
    public function testRepeatedStartPreservesTerminalAndStopResetsItOnce(): void
    {
        $writer = StringWriter::new();
        $rawMode = new TestRawMode();
        $terminal = new Terminal(
            AnsiPainter::new($writer),
            new AggregateInformationProvider([]),
            $rawMode,
            new ArrayEventProvider([]),
        );
        $view = new TuiView();
        $terminalProperty = new \ReflectionProperty($view, 'terminal');
        $terminalProperty->setValue($view, $terminal);
        (new \ReflectionProperty($view, 'active'))->setValue($view, true);
        $rawMode->enable();

        $view->start();
        self::assertSame($terminal, $terminalProperty->getValue($view));
        self::assertTrue($rawMode->isEnabled());

        $view->stop();
        self::assertFalse($rawMode->isEnabled());

        $view->stop();
        self::assertSame("\033[?1049l\033c\033[?25h", $writer->toString());
    }
}
