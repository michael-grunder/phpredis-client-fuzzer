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
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/** Tail the server's request journal without retaining the full history in memory. */
final class SoakMonitor
{
    private ?Terminal $terminal = null;
    private ?Display $display = null;
    /** @var resource */
    private $events;
    /** @var list<TableRow> */
    private array $recent = [];
    /** @var array<int, array{path: string, started: float}> */
    private array $active = [];
    private int $started = 0;
    private int $completed = 0;
    private float $lastRender = 0;
    private readonly float $since;

    public function __construct(private readonly string $directory, private readonly int $history, bool $tui)
    {
        $events = fopen($directory . '/requests.jsonl', 'x+');
        if ($events === false) {
            throw new \RuntimeException('cannot create request journal');
        }
        $this->events = $events;
        $this->since = microtime(true);
        if ($tui) {
            $this->terminal = Terminal::new();
            $this->display = DisplayBuilder::default(PhpTermBackend::new($this->terminal))->fullscreen()->build();
            $this->terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());
        }
    }

    public function render(string $connection, bool $force = false): void
    {
        fseek($this->events, 0, SEEK_CUR); // Clear EOF after another process appends.
        // Limit work per tick so a busy journal cannot starve worker supervision.
        for ($index = 0; $index < 1000; $index++) {
            $offset = ftell($this->events);
            $line = fgets($this->events, 65536);
            if ($line === false) {
                break;
            }
            if (!str_ends_with($line, "\n")) {
                if ($offset !== false) {
                    fseek($this->events, $offset);
                }
                break;
            }
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event) || !is_string($event['path'] ?? null) || !is_int($event['pid'] ?? null)
                || (!is_float($event['started'] ?? null) && !is_int($event['started'] ?? null))
                || !in_array($event['event'] ?? null, ['start', 'finish'], true)) {
                throw new \RuntimeException('invalid request journal');
            }
            if ($event['event'] === 'start') {
                $this->started++;
                $this->active[$event['pid']] = ['path' => $event['path'], 'started' => (float) $event['started']];
            } else {
                $this->completed++;
                unset($this->active[$event['pid']]);
            }
            $summary = sprintf('%s pid=%d %s', $event['event'] === 'start' ? 'START' : 'END', $event['pid'], $event['path']);
            if ($event['event'] === 'finish') {
                $summary .= ' exit=' . json_encode($event['exit'] ?? null) . ' seconds=' . json_encode($event['elapsed'] ?? null);
            }
            if ($event['event'] === 'finish') {
                $exit = $event['exit'] ?? null;
                if ((!is_float($event['elapsed'] ?? null) && !is_int($event['elapsed'] ?? null))
                    || !is_int($event['status'] ?? null)
                    || ($exit !== null && !is_int($exit))) {
                    throw new \RuntimeException('invalid request completion');
                }
                $this->recent[] = $this->row($event['path'], $event['pid'], (float) $event['elapsed'],
                    $exit, $event['status']);
                $this->recent = array_slice($this->recent, -$this->history);
            }
            if ($this->display === null) {
                echo $summary . "\n";
            }
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastRender < ($this->display === null ? 10 : 1)) {
            return;
        }
        $this->lastRender = $now;
        $summary = sprintf('SAPI uptime=%ds requests=%d completed=%d in-flight=%d', (int) ($now - $this->since),
            $this->started, $this->completed, $this->started - $this->completed);
        if ($this->display !== null) {
            $this->display->draw($this->build($connection, $now));
        } else {
            echo $summary . "\n";
        }
    }

    private function build(string $connection, float $now): Widget
    {
        $elapsed = max(0, (int) ($now - $this->since));
        $metrics = TableWidget::default()
            ->widths(Constraint::length(12), Constraint::percentage(50), Constraint::length(12), Constraint::percentage(50))
            ->rows(
                TableRow::fromStrings('Uptime', sprintf('%dd %02d:%02d:%02d', intdiv($elapsed, 86400),
                    intdiv($elapsed % 86400, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60),
                    'Requests', number_format($this->started)),
                TableRow::fromStrings('Completed', number_format($this->completed),
                    'In flight', number_format($this->started - $this->completed)),
                TableRow::fromStrings('Requests/s', sprintf('%.1f', $this->completed / max(1, $now - $this->since)),
                    'History', (string) $this->history),
            );
        $header = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' redis-client-tester-sapi '))
            ->widget(GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::length(3), Constraint::min(2))
                ->widgets($metrics, ParagraphWidget::fromLines(
                    Line::fromString('Directory: ' . $this->directory),
                    Line::fromString('Client: ' . $connection),
                )));

        $rows = [];
        foreach ($this->active as $pid => $request) {
            $rows[] = $this->row($request['path'], $pid, max(0, $now - $request['started']), null, null);
        }
        $table = TableWidget::default()
            ->header(TableRow::fromStrings('Status', 'PID', 'Time', 'Exit', 'HTTP', 'Request'))
            ->widths(Constraint::length(7), Constraint::length(8), Constraint::length(9),
                Constraint::length(4), Constraint::length(4), Constraint::min(10))
            ->rows(...[...$rows, ...array_reverse($this->recent)]);
        $table->columnSpacing = 2;

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(9), Constraint::min(3))
            ->widgets($header, BlockWidget::default()
                ->borders(Borders::ALL)
                ->titles(Title::fromString(' requests — running, then newest completed — Ctrl+C to stop '))
                ->widget($table));
    }

    private function row(string $path, int $pid, float $elapsed, ?int $exit, ?int $http): TableRow
    {
        [$status, $colour] = match (true) {
            $http === null => ['RUN', 'cyan'],
            $exit === 0 && $http === 200 => ['OK', 'green'],
            $exit === 78 => ['STARTUP', 'yellow'],
            default => ['FAIL', 'red'],
        };
        return TableRow::fromCells(
            TableCell::fromLine(Line::parse("<fg={$colour}>{$status}</>")),
            TableCell::fromString((string) $pid),
            TableCell::fromString(sprintf('%.3fs', $elapsed)),
            TableCell::fromString($exit === null ? '-' : (string) $exit),
            TableCell::fromString($http === null ? '-' : (string) $http),
            TableCell::fromString(ltrim($path, '/')),
        );
    }

    public function stop(): void
    {
        $this->terminal?->execute(Actions::alternateScreenDisable(), Actions::cursorShow());
        fclose($this->events);
    }
}
