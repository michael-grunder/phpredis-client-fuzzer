<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

use PhpTui\Term\Actions;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;

/** Client-local progress; never consumes the server's shared request journal. */
final class ClientMonitor
{
    private ?Terminal $terminal = null;
    private ?Display $display = null;
    /** @var array<int, array{path: string, seed: int, started: float}> */
    private array $active = [];
    /** @var list<TableRow> */
    private array $recent = [];
    private int $started = 0;
    private int $completed = 0;
    private int $failed = 0;
    private float $lastRender = 0;
    private readonly float $since;

    public function __construct(
        private readonly string $server,
        private readonly int $seed,
        private readonly int $requests,
        private readonly string $jobs,
    ) {
        $this->since = microtime(true);
    }

    public function started(int $number, int $seed, string $path): void
    {
        $this->started++;
        $this->active[$number] = ['path' => $path, 'seed' => $seed, 'started' => microtime(true)];
    }

    public function completed(int $number, int $exit): void
    {
        $request = $this->active[$number];
        $this->recent[] = $this->row($number, $request['seed'], $request['path'], microtime(true) - $request['started'], $exit);
        $this->recent = array_slice($this->recent, -10);
        unset($this->active[$number]);
        $this->completed++;
        $this->failed += (int) ($exit !== 0);
    }

    public function render(int $targetJobs): void
    {
        $now = microtime(true);
        if ($now - $this->lastRender < 0.25) {
            return;
        }
        $this->lastRender = $now;
        if ($this->display === null) {
            $this->terminal = Terminal::new();
            $this->display = DisplayBuilder::default(PhpTermBackend::new($this->terminal))->fullscreen()->build();
            $this->terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());
        }
        $elapsed = max(0, (int) ($now - $this->since));
        $metrics = TableWidget::default()
            ->widths(Constraint::length(12), Constraint::percentage(50), Constraint::length(12), Constraint::percentage(50))
            ->rows(
                TableRow::fromStrings('Uptime', sprintf('%dd %02d:%02d:%02d', intdiv($elapsed, 86400),
                    intdiv($elapsed % 86400, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60),
                    'Seed', (string) $this->seed),
                TableRow::fromStrings('Requests', number_format($this->started) . '/' . ($this->requests === 0 ? 'unlimited' : number_format($this->requests)),
                    'Completed', number_format($this->completed)),
                TableRow::fromStrings('In flight', (string) count($this->active), 'Failed', number_format($this->failed)),
                TableRow::fromStrings('Requests/s', sprintf('%.1f', $this->completed / max(1, $now - $this->since)),
                    'Jobs', $targetJobs . ' (configured ' . $this->jobs . ')'),
            );
        $rows = [];
        foreach ($this->active as $number => $request) {
            $rows[] = $this->row($number, $request['seed'], $request['path'], $now - $request['started'], null);
        }
        $table = TableWidget::default()
            ->header(TableRow::fromStrings('Status', '#', 'Seed', 'Time', 'Exit', 'Request'))
            ->widths(Constraint::length(7), Constraint::length(8), Constraint::length(10),
                Constraint::length(9), Constraint::length(4), Constraint::min(10))
            ->rows(...[...$rows, ...array_reverse($this->recent)]);
        $table->columnSpacing = 2;
        $this->display->draw(GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(6), Constraint::min(3))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)
                    ->titles(Title::fromString(' sapi client — ' . $this->server . ' '))->widget($metrics),
                BlockWidget::default()->borders(Borders::ALL)
                    ->titles(Title::fromString(' requests — running, then newest completed — Ctrl+C to stop '))->widget($table),
            ));
    }

    private function row(int $number, int $seed, string $path, float $elapsed, ?int $exit): TableRow
    {
        [$status, $colour] = match ($exit) {
            null => ['RUN', 'cyan'],
            0 => ['OK', 'green'],
            78 => ['STARTUP', 'yellow'],
            default => ['FAIL', 'red'],
        };
        return TableRow::fromCells(
            TableCell::fromLine(Line::parse("<fg={$colour}>{$status}</>")),
            TableCell::fromString((string) $number),
            TableCell::fromString((string) $seed),
            TableCell::fromString(sprintf('%.3fs', max(0, $elapsed))),
            TableCell::fromString($exit === null ? '-' : (string) $exit),
            TableCell::fromString(basename($path)),
        );
    }

    public function stop(): void
    {
        $this->terminal?->execute(Actions::alternateScreenDisable(), Actions::cursorShow());
        $this->terminal = null;
    }
}
