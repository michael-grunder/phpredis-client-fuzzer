<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

final class Tui
{
    private Terminal $terminal;

    private PhpTermBackend $backend;

    private Display $display;

    private bool $active = false;

    private bool $resized = false;

    public function __construct(
        private readonly bool $cluster,
        private readonly string $source,
        private readonly float $interval,
    ) {
    }

    public function start(): void
    {
        $this->terminal = Terminal::new();
        $this->backend = PhpTermBackend::new($this->terminal);
        $this->display = DisplayBuilder::default($this->backend)->fullscreen()->build();
        $this->terminal->enableRawMode();
        $this->terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());
        $this->active = true;
    }

    public function render(CounterTracker $tracker, Sample $sample, float $elapsed): void
    {
        if ($this->active) {
            $this->display->draw($this->build($tracker, $sample, $elapsed));
        }
    }

    public function quitRequested(): bool
    {
        while ($this->active && ($event = $this->terminal->events()->next()) !== null) {
            if ($event instanceof CharKeyEvent) {
                if (strtolower($event->char) === 'q') {
                    return true;
                }
                if (strtolower($event->char) === 'c' && ($event->modifiers & KeyModifiers::CONTROL) !== 0) {
                    return true;
                }
            }
            if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
                return true;
            }
            if ($event instanceof TerminalResizedEvent) {
                $this->resized = true;
            }
        }

        return false;
    }

    public function resizeRequested(): bool
    {
        $resized = $this->resized;
        $this->resized = false;

        return $resized;
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->terminal->execute(Actions::alternateScreenDisable(), Actions::cursorShow());
        $this->terminal->disableRawMode();
    }

    public static function groupsForWidth(int $width, bool $cluster): int
    {
        return max(1, intdiv(max(1, $width - 2), $cluster ? 42 : 30));
    }

    private function build(CounterTracker $tracker, Sample $sample, float $elapsed): Widget
    {
        $width = $this->backend->size()->width;
        $errors = $sample->errors === [] ? 'none' : implode(' | ', $sample->errors);
        $header = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Redis command stats '))
            ->widget(ParagraphWidget::fromLines(
                Line::fromString(sprintf(
                    'source %s   elapsed %s   refresh %.2fs',
                    $this->source,
                    $this->elapsed($elapsed),
                    $this->interval,
                )),
                Line::fromString(sprintf(
                    'calls %s   commands %d   primaries %d   replicas %d',
                    number_format($tracker->calls()),
                    $tracker->commands(),
                    $sample->nodes(NodeRole::Primary),
                    $sample->nodes(NodeRole::Replica),
                )),
                Line::fromString('errors ' . $errors),
            ));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(5), Constraint::min(3))
            ->widgets($header, $this->table($tracker->totals(), $width));
    }

    /** @param list<array{command: string, primary: int, replica: int}> $totals */
    private function table(array $totals, int $width): Widget
    {
        $groups = self::groupsForWidth($width, $this->cluster);
        $columnsPerGroup = $this->cluster ? 3 : 2;
        $spacing = ($groups * $columnsPerGroup) - 1;
        $groupWidth = max($this->cluster ? 24 : 18, intdiv(max(1, $width - 2 - $spacing), $groups));
        $numberWidth = $this->cluster ? 9 : 11;
        $commandWidth = max(6, $groupWidth - ($this->cluster ? $numberWidth * 2 : $numberWidth));

        $headers = [];
        $constraints = [];
        for ($group = 0; $group < $groups; $group++) {
            $headers[] = TableCell::fromString('command');
            $constraints[] = Constraint::length($commandWidth);
            if ($this->cluster) {
                $headers[] = TableCell::fromString('primary');
                $constraints[] = Constraint::length($numberWidth);
                $headers[] = TableCell::fromString('replicas');
                $constraints[] = Constraint::length($numberWidth);
            } else {
                $headers[] = TableCell::fromString('calls');
                $constraints[] = Constraint::length($numberWidth);
            }
        }

        $rows = [];
        $rowCount = (int) ceil(count($totals) / $groups);
        for ($row = 0; $row < $rowCount; $row++) {
            $cells = [];
            for ($group = 0; $group < $groups; $group++) {
                $total = $totals[($row * $groups) + $group] ?? null;
                $cells[] = TableCell::fromString($total['command'] ?? '');
                $cells[] = TableCell::fromString($total === null ? '' : number_format($total['primary']));
                if ($this->cluster) {
                    $cells[] = TableCell::fromString($total === null ? '' : number_format($total['replica']));
                }
            }
            $rows[] = TableRow::fromCells(...$cells);
        }

        $table = TableWidget::default()
            ->header(TableRow::fromCells(...$headers))
            ->widths(...$constraints)
            ->rows(...$rows);
        $table->columnSpacing = 1;

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' since monitor start — most frequent first — q to quit '))
            ->widget($table);
    }

    private function elapsed(float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
