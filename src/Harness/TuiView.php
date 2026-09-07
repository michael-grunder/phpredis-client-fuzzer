<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * An AFL-style full-screen dashboard: a summary panel above a live table of
 * the most recent runs.
 */
final class TuiView implements View
{
    private Terminal $terminal;

    private Display $display;

    private float $lastRender = 0.0;

    private bool $active = false;

    public function start(): void
    {
        $this->terminal = Terminal::new();
        $this->display = DisplayBuilder::default(PhpTermBackend::new($this->terminal))
            ->fullscreen()
            ->build();

        $this->terminal->enableRawMode();
        $this->terminal->execute(
            Actions::alternateScreenEnable(),
            Actions::cursorHide(),
        );
        $this->active = true;
    }

    public function render(Stats $stats, DashboardState $state): void
    {
        if (!$this->active) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastRender < 0.1) {
            return;
        }
        $this->lastRender = $now;

        $this->display->draw($this->build($stats, $state));
    }

    public function note(string $message): void
    {
        // Events are visible in the runs table; nothing extra to print.
    }

    public function quitRequested(): bool
    {
        if (!$this->active) {
            return false;
        }

        while (($event = $this->terminal->events()->next()) !== null) {
            if ($event instanceof CharKeyEvent) {
                $char = strtolower($event->char);
                if ($char === 'q') {
                    return true;
                }
                if ($char === 'c' && ($event->modifiers & KeyModifiers::CONTROL) !== 0) {
                    return true;
                }
            }
            if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
                return true;
            }
        }

        return false;
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;

        $this->terminal->execute(
            Actions::alternateScreenDisable(),
            Actions::cursorShow(),
        );
        $this->terminal->disableRawMode();
    }

    private function build(Stats $stats, DashboardState $state): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(10), Constraint::min(3))
            ->widgets(
                $this->header($stats, $state),
                $this->runs($state),
            );
    }

    private function header(Stats $stats, DashboardState $state): Widget
    {
        $limits = [];
        if ($state->maxRuns !== null) {
            $limits[] = "runs {$state->maxRuns}";
        }
        if ($state->maxReproducers !== null) {
            $limits[] = "repros {$state->maxReproducers}";
        }
        if ($state->maxSeconds !== null) {
            $limits[] = 'time ' . (int) $state->maxSeconds . 's';
        }

        $mode = [];
        if ($state->rr) {
            $mode[] = $state->rrChaos ? 'rr+chaos' : 'rr';
        }
        $mode[] = 'capture:' . implode('+', $state->capture);
        if ($state->reduce !== null) {
            $mode[] = 'reduce:' . $state->reduce;
        }

        $lines = [
            sprintf(
                '<fg=cyan>elapsed</> %s   <fg=cyan>jobs</> %d/%d   <fg=cyan>exec/s</> %.1f   <fg=cyan>steps</> %d',
                $stats->formatElapsed(),
                count($state->active),
                $state->jobs,
                $stats->execPerSecond(),
                $state->steps,
            ),
            sprintf(
                '<fg=cyan>runs</> %d done, %d started   <fg=green>passed</> %d   <fg=white>skipped</> %d',
                $stats->completed,
                $stats->started,
                $stats->passed,
                $stats->skipped,
            ),
            $this->counterLine($stats, $state),
            sprintf(
                '<fg=cyan>ports</> %s   <fg=cyan>mode</> %s   <fg=cyan>stop-after</> %s',
                $state->ports === [] ? '-' : implode(',', $state->ports),
                implode(' ', $mode),
                $limits === [] ? 'never' : implode(', ', $limits),
            ),
            sprintf('<fg=cyan>php</> %s', $state->phpVersion),
            sprintf('<fg=cyan>out</> %s', $state->output),
        ];

        if ($state->shutdown !== null) {
            array_unshift($lines, '<fg=red>' . $state->shutdown . '</>');
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' phpredis-fuzz-harness '))
            ->widget(ParagraphWidget::fromLines(
                ...array_map(static fn (string $line): Line => Line::parse($line), $lines),
            ));
    }

    private function counterLine(Stats $stats, DashboardState $state): string
    {
        $parts = [
            sprintf('<fg=yellow>failures</> %d', $stats->failures),
            sprintf('<fg=red>crashes</> %d', $stats->crashes),
            sprintf('<fg=magenta>timeouts</> %d', $stats->timeouts),
        ];
        if (in_array('leaks', $state->capture, true)) {
            $parts[] = sprintf('<fg=blue>leaks</> %d', $stats->leaks);
        }
        $parts[] = sprintf('<fg=green>reproducers</> %d', $stats->reproducers);
        if ($stats->failedReproducers > 0) {
            $parts[] = sprintf('<fg=red>failed repros</> %d', $stats->failedReproducers);
        }
        if ($stats->rrAborts > 0) {
            $parts[] = sprintf('<fg=yellow>rr aborts</> %d', $stats->rrAborts);
        }
        $parts[] = sprintf('<fg=cyan>reduced</> %d', $stats->reductions);

        return implode('   ', $parts);
    }

    private function runs(DashboardState $state): Widget
    {
        $rows = [];
        foreach ($state->active as $job) {
            $rows[] = $this->row($job);
        }
        foreach ($state->recent as $job) {
            $rows[] = $this->row($job);
        }

        $table = TableWidget::default()
            ->header(TableRow::fromCells(
                TableCell::fromString('run'),
                TableCell::fromString('slot'),
                TableCell::fromString('port'),
                TableCell::fromString('steps'),
                TableCell::fromString('seed'),
                TableCell::fromString('time'),
                TableCell::fromString('status'),
                TableCell::fromString('notes'),
            ))
            ->widths(
                Constraint::length(6),
                Constraint::length(4),
                Constraint::length(6),
                Constraint::length(11),
                Constraint::length(12),
                Constraint::length(7),
                Constraint::length(8),
                Constraint::min(10),
            )
            ->rows(...$rows);
        $table->columnSpacing = 2;

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' runs — newest first — press q to quit '))
            ->widget($table);
    }

    private function row(Job $job): TableRow
    {
        [$label, $colour] = match ($job->status) {
            JobStatus::Running => ['RUN', 'cyan'],
            JobStatus::Passed => ['ok', 'green'],
            JobStatus::Skipped => ['skip', 'white'],
            JobStatus::Failed => ['FAIL', 'yellow'],
            JobStatus::Crashed => ['CRASH', 'red'],
            JobStatus::TimedOut => ['TIMEOUT', 'magenta'],
            JobStatus::Leaked => ['LEAK', 'blue'],
            JobStatus::StartupFailed => ['STARTUP', 'red'],
            JobStatus::CaptureFailed => ['NOTRACE', 'red'],
            JobStatus::RrAborted => ['RRABORT', 'yellow'],
        };

        $steps = $job->reducedSteps !== null
            ? sprintf('%d<-%d', $job->reducedSteps, $job->steps)
            : (string) $job->steps;

        $note = $job->note;
        if ($note === '' && $job->reproDir !== null) {
            $note = basename($job->reproDir);
        } elseif ($job->reproDir !== null && !str_contains($note, '/')) {
            $note .= '  ' . basename($job->reproDir);
        }

        return TableRow::fromCells(
            TableCell::fromString((string) $job->id),
            TableCell::fromString((string) $job->slot),
            TableCell::fromString($job->port !== null ? (string) $job->port : '-'),
            TableCell::fromString($steps),
            TableCell::fromString((string) $job->seed),
            TableCell::fromString(sprintf('%.1fs', $job->duration())),
            TableCell::fromLine(Line::parse("<fg={$colour}>{$label}</>")),
            TableCell::fromString($note),
        );
    }
}
