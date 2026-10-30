<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Viewer\Reproducer;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * Browses harness reproducer directories by kind, then capture (or distinct
 * defect signature with `g`), Enter for the full analysis. Read-only: nothing
 * under the reproducer directory is written, moved, or deleted.
 */
final class ReproViewerApplication
{
    private const KIND_COLOURS = [
        'sanitizer' => 'red', 'crash' => 'red', 'memory' => 'magenta',
        'leak' => 'blue', 'timeout' => 'yellow', 'failure' => 'white',
    ];

    /** @var list<Reproducer> */
    private array $repros = [];

    /** @var list<list<Reproducer>> Rows of the current view; one capture each unless grouped. */
    private array $rows = [];

    private bool $grouped = false;

    private ?string $kind = null;

    private ?string $gdb = null;

    private string $root = '';

    /** @var resource */
    private $output;

    /** @param resource|null $output Defaults to STDOUT. */
    public function __construct($output = null)
    {
        $this->output = $output ?? STDOUT;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $root = 'reproducers';
            if ($arguments !== [] && !str_starts_with($arguments[0], '--')) {
                $root = array_shift($arguments);
            }
            $options = Options::parse($arguments, ['show'], ['help', 'list', 'group', 'backtraces', 'no-gdb']);
            if ($options->has('help')) {
                fwrite($this->output, self::usage());

                return ExitCode::SUCCESS;
            }
            if (!is_dir($root)) {
                throw new \InvalidArgumentException("not a directory: {$root}");
            }
            $this->root = $root;
            $this->kind = null;
            $this->gdb = $options->has('no-gdb') ? null : self::locate('gdb');
            $this->grouped = $options->has('group');
            $this->load($options->has('backtraces'));

            if ($options->has('show')) {
                return $this->show($options->string('show', ''));
            }
            if ($options->has('list') || !stream_isatty($this->output)) {
                $this->printList();

                return ExitCode::SUCCESS;
            }

            return $this->interactive();
        } catch (\InvalidArgumentException $error) {
            fwrite(STDERR, 'repros: ' . $error->getMessage() . "\n");

            return ExitCode::STARTUP;
        }
    }

    private function load(bool $backtraces): void
    {
        $this->repros = Reproducer::scan($this->root);
        if ($backtraces) {
            foreach ($this->repros as $repro) {
                $repro->analyzeCore($this->gdb);
            }
        }
        $this->regroup();
    }

    private function regroup(): void
    {
        $repros = array_values(array_filter($this->repros, fn (Reproducer $r): bool => $this->kind === null || $r->kind() === $this->kind));
        if (!$this->grouped) {
            $this->rows = array_map(static fn (Reproducer $r): array => [$r], $repros);

            return;
        }
        $groups = [];
        foreach ($repros as $repro) {
            $groups[$repro->signature()][] = $repro;
        }
        // Most frequent defect first; inside a group the smallest reproducer leads.
        foreach ($groups as &$group) {
            usort($group, static fn (Reproducer $a, Reproducer $b): int => self::stepCount($a) <=> self::stepCount($b));
        }
        unset($group);
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => count($b) <=> count($a));
        $this->rows = $rows;
    }

    private static function stepCount(Reproducer $repro): int
    {
        return (int) explode('←', $repro->steps())[0];
    }

    // ------------------------------------------------------------------ plain

    private function printList(): void
    {
        foreach ($this->rows as $index => $row) {
            $repro = $row[0];
            fprintf(
                $this->output,
                "%3d  %-9s %-14s %s%s\n",
                $index + 1,
                $repro->kind(),
                $this->grouped ? count($row) . '×  ' . $repro->steps() : $repro->steps(),
                $repro->id . '  ',
                $repro->headline(),
            );
        }
        fprintf($this->output, "%d captures, %d distinct signatures under %s\n", count($this->repros), $this->signatureCount(), $this->root);
    }

    private function show(string $which): int
    {
        $row = ctype_digit($which) ? ($this->rows[(int) $which - 1] ?? null) : null;
        foreach ($this->rows as $candidate) {
            foreach ($candidate as $repro) {
                if ($repro->id === $which || $repro->dir === rtrim($which, '/')) {
                    $row = [$repro];
                }
            }
        }
        if ($row === null) {
            throw new \InvalidArgumentException("no reproducer {$which}; --list shows the numbers and ids");
        }
        $row[0]->analyzeCore($this->gdb);
        foreach ($this->sections($row) as [$title, $lines]) {
            fwrite($this->output, "== {$title}\n" . implode("\n", $lines) . "\n\n");
        }

        return ExitCode::SUCCESS;
    }

    /**
     * @param list<Reproducer> $row
     * @return list<array{string, list<string>}>
     */
    private function sections(array $row): array
    {
        $sections = $row[0]->details();
        if (count($row) > 1) {
            $others = array_map(static fn (Reproducer $r): string => $r->id . '  (' . $r->steps() . ' steps)', array_slice($row, 1));
            array_splice($sections, 1, 0, [[count($row) . ' captures share this signature', $others]]);
        }

        return $sections;
    }

    private function signatureCount(): int
    {
        return count(array_unique(array_map(static fn (Reproducer $r): string => $r->signature(), $this->repros)));
    }

    // ------------------------------------------------------------ interactive

    private function interactive(): int
    {
        $terminal = Terminal::new();
        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());

        try {
            $this->loop($terminal, $display);
        } finally {
            $terminal->execute(Actions::alternateScreenDisable(), Actions::cursorShow());
            $terminal->disableRawMode();
        }

        return ExitCode::SUCCESS;
    }

    private function loop(Terminal $terminal, Display $display): void
    {
        $categories = true;
        $categoryTable = new TableState(0, 0);
        $table = new TableState(0, $this->rows === [] ? null : 0);
        $detail = null;
        $scroll = 0;
        $notice = null;
        $dirty = true;
        $lastDraw = 0.0;

        while (true) {
            if ($dirty || microtime(true) - $lastDraw > 0.5) {
                $display->draw($categories
                    ? $this->categoryView($categoryTable, $notice)
                    : ($detail === null ? $this->listView($table, $notice) : $this->detailView($detail, $scroll)));
                $lastDraw = microtime(true);
                $dirty = false;
                $notice = null;
            }

            $event = $terminal->events()->next();
            if ($event === null) {
                usleep(20000);
                continue;
            }
            $dirty = true;
            $key = $event instanceof CharKeyEvent ? $event->char : null;
            $code = $event instanceof CodedKeyEvent ? $event->code : null;
            if ($key === 'c' && ($event->modifiers & KeyModifiers::CONTROL) !== 0) {
                return;
            }
            $page = max(1, $display->viewportArea()->height - 8);

            if ($categories) {
                $kinds = array_keys($this->categoryCounts());
                if ($code === KeyCode::Esc || $key === 'q') {
                    return;
                }
                if ($code === KeyCode::Enter || $code === KeyCode::Right || $key === 'l') {
                    $this->kind = $kinds[$categoryTable->selected ?? 0] ?? null;
                    $this->regroup();
                    $table = new TableState(0, $this->rows === [] ? null : 0);
                    $categories = false;
                } elseif ($key === 'r') {
                    $this->load(false);
                    $categoryTable = new TableState(0, 0);
                    $notice = 'rescanned: ' . count($this->repros) . ' captures';
                } else {
                    $categoryTable->selected = $this->move($categoryTable->selected ?? 0, $code, $key, $page, count($kinds));
                }
                continue;
            }

            if ($detail !== null) {
                $length = count($this->detailLines($detail));
                $selected = $table->selected ?? 0;
                if ($code === KeyCode::Esc || $code === KeyCode::Left || $code === KeyCode::Backspace || $key === 'q' || $key === 'h') {
                    $detail = null;
                } elseif ($key === 'n' || $key === 'p') {
                    $table->selected = max(0, min(count($this->rows) - 1, $selected + ($key === 'n' ? 1 : -1)));
                    $detail = $this->open($display, $table->selected);
                    $scroll = 0;
                } else {
                    $scroll = $this->move($scroll, $code, $key, $page, max(0, $length - $page));
                }
                continue;
            }

            if ($code === KeyCode::Esc || $code === KeyCode::Left || $code === KeyCode::Backspace || $key === 'q' || $key === 'h') {
                $categories = true;
                $categoryTable->selected = min($categoryTable->selected ?? 0, count($this->categoryCounts()));
                continue;
            }
            if ($key === 'r') {
                $this->load(false);
                $table = new TableState(0, $this->rows === [] ? null : 0);
                $notice = 'rescanned: ' . count($this->repros) . ' captures';
                continue;
            }
            if ($this->rows === []) {
                continue;
            }
            if ($code === KeyCode::Enter || $code === KeyCode::Right || $key === 'l') {
                $detail = $this->open($display, $table->selected ?? 0);
                $scroll = 0;
            } elseif ($key === 'g') {
                $this->grouped = !$this->grouped;
                $this->regroup();
                $table = new TableState(0, 0);
            } else {
                $table->selected = $this->move($table->selected ?? 0, $code, $key, $page, count($this->rows) - 1);
            }
        }
    }

    private function move(int $position, ?KeyCode $code, ?string $key, int $page, int $max): int
    {
        $position = match (true) {
            $code === KeyCode::Up || $key === 'k' => $position - 1,
            $code === KeyCode::Down || $key === 'j' => $position + 1,
            $code === KeyCode::PageUp => $position - $page,
            $code === KeyCode::PageDown || $key === ' ' => $position + $page,
            $code === KeyCode::Home => 0,
            $code === KeyCode::End => $max,
            default => $position,
        };

        return max(0, min($max, $position));
    }

    /** @return list<Reproducer> */
    private function open(Display $display, int $index): array
    {
        $row = $this->rows[$index];
        if ($row[0]->cores() !== [] && $row[0]->coreBacktrace === null && $row[0]->coreProblem === null && $this->gdb !== null) {
            $display->draw($this->frame(' working ', [Line::fromString('running gdb on ' . $row[0]->cores()[0] . ' …')], ''));
            $row[0]->analyzeCore($this->gdb);
            // A backtrace can change the signature, so groups are rebuilt next time they are toggled.
        }

        return $row;
    }

    private function listView(TableState $state, ?string $notice): Widget
    {
        $kinds = $this->categoryCounts();
        arsort($kinds);
        $counts = [];
        foreach ($kinds as $kind => $count) {
            $counts[] = sprintf('<fg=%s>%s</> %d', self::KIND_COLOURS[$kind] ?? 'white', $kind, $count);
        }
        $header = [
            Line::parse(sprintf(
                '<fg=cyan>captures</> %d   <fg=cyan>signatures</> %d   %s',
                count($this->repros),
                $this->signatureCount(),
                implode('   ', $counts),
            )),
            Line::fromSpans(Span::styled('root ', Style::default()->fg(AnsiColor::Cyan)), Span::fromString($this->root)),
        ];
        if ($notice !== null) {
            $header[] = Line::fromString($notice);
        }

        $rows = [];
        foreach ($this->rows as $row) {
            $repro = $row[0];
            $kind = $repro->kind() . ($repro->replayable() ? '' : '!');
            // Grouped rows show the whole signature: what tells two groups apart is often its last frame.
            $rows[] = TableRow::fromCells(
                TableCell::fromLine(Line::parse(sprintf('<fg=%s>%s</>', self::KIND_COLOURS[$repro->kind()] ?? 'white', $kind))),
                TableCell::fromString($this->grouped ? count($row) . '×' : substr($repro->capturedAt(), 5, 14)),
                TableCell::fromString($repro->steps()),
                TableCell::fromString($this->grouped ? $repro->signature() : $repro->id),
                ...($this->grouped ? [] : [TableCell::fromString($repro->headline())]),
            );
        }
        $table = TableWidget::default()
            ->header(TableRow::fromCells(
                TableCell::fromString('kind'),
                TableCell::fromString($this->grouped ? 'count' : 'captured'),
                TableCell::fromString('steps'),
                TableCell::fromString($this->grouped ? 'signature (enter for details)' : 'id'),
                ...($this->grouped ? [] : [TableCell::fromString('headline')]),
            ))
            ->widths(
                Constraint::length(10),
                Constraint::length($this->grouped ? 6 : 14),
                Constraint::length(11),
                ...($this->grouped ? [Constraint::min(20)] : [Constraint::length(26), Constraint::min(20)]),
            )
            ->state($state)
            ->highlightSymbol('▶ ')
            ->highlightStyle(Style::default()->addModifier(Modifier::REVERSED))
            ->rows(...$rows);
        $table->columnSpacing = 1;

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(count($header) + 2), Constraint::min(3), Constraint::length(1))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString(' repros '))
                    ->widget(ParagraphWidget::fromLines(...$header)),
                BlockWidget::default()->borders(Borders::ALL)
                    ->titles(Title::fromString(' ' . ($this->kind ?? 'all') . ($this->grouped ? ': grouped by signature, most frequent first ' : ': captures, newest first ')))
                    ->widget($this->rows === [] ? ParagraphWidget::fromString('no reproducers found') : $table),
                ParagraphWidget::fromString(' ↑↓/jk move  PgUp/PgDn  Home/End  enter details  g group by signature  r rescan  esc/q categories'),
            );
    }

    /** @return array<string, int> */
    private function categoryCounts(): array
    {
        $counts = array_count_values(array_map(static fn (Reproducer $r): string => $r->kind(), $this->repros));
        ksort($counts);

        return $counts;
    }

    private function categoryView(TableState $state, ?string $notice): Widget
    {
        $rows = [];
        foreach ($this->categoryCounts() as $kind => $count) {
            $rows[] = TableRow::fromCells(
                TableCell::fromLine(Line::fromSpan(Span::styled($kind, Style::default()->fg(AnsiColor::fromName(self::KIND_COLOURS[$kind] ?? 'white'))))),
                TableCell::fromString((string) $count),
            );
        }
        $rows[] = TableRow::fromStrings('All captures', (string) count($this->repros));
        $table = TableWidget::default()
            ->header(TableRow::fromStrings('Flaw type', 'Captures'))
            ->widths(Constraint::min(20), Constraint::length(12))
            ->state($state)
            ->highlightSymbol('▶ ')
            ->highlightStyle(Style::default()->addModifier(Modifier::REVERSED))
            ->rows(...$rows);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(2), Constraint::min(3), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromLines(Line::fromString($this->root), Line::fromString($notice ?? count($this->repros) . ' captures')),
                BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString(' flaw types '))
                    ->widget($this->repros === [] ? ParagraphWidget::fromString('no reproducers found') : $table),
                ParagraphWidget::fromString(' ↑↓/jk move  enter open category  r rescan  q quit'),
            );
    }

    /**
     * @param list<Reproducer> $row
     * @return list<Line>
     */
    private function detailLines(array $row): array
    {
        $lines = [];
        foreach ($this->sections($row) as [$title, $body]) {
            $lines[] = Line::fromSpan(Span::styled($title, Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)));
            foreach ($body as $text) {
                $lines[] = Line::fromString('  ' . $text);
            }
            $lines[] = Line::fromString('');
        }

        return $lines;
    }

    /** @param list<Reproducer> $row */
    private function detailView(array $row, int $scroll): Widget
    {
        $lines = $this->detailLines($row);

        return $this->frame(
            ' ' . $row[0]->id . ' ',
            array_slice($lines, $scroll),
            sprintf(' ↑↓/jk scroll  PgUp/PgDn  Home/End  n/p next/previous  esc back   line %d/%d', $scroll + 1, count($lines)),
        );
    }

    /** @param list<Line> $lines */
    private function frame(string $title, array $lines, string $footer): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(3), Constraint::length(1))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString($title))
                    ->widget(ParagraphWidget::fromLines(...$lines)),
                ParagraphWidget::fromString($footer),
            );
    }

    private static function locate(string $name): ?string
    {
        $path = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    private static function usage(): string
    {
        return <<<'TXT'
Usage: repros [DIR] [options]

Browse harness reproducers under DIR (default: reproducers). DIR may be an
--output root, one kind directory, or a single capture. Read-only.
The TUI opens with flaw types and capture counts; Enter opens a category.

Each capture gets a one-line headline of the defect, from its sanitizer report
(type, access, defect site and caller, where it was freed), a glibc assertion
message, a gdb backtrace of a collected core, or the harness' memory, leak, and
timeout diagnostics. Captures with the same signature (defect type and top
frames, no addresses or seeds) are the same bug; `g` groups them.

Options:
  --list         Print the list instead of the TUI (default when not a tty)
  --group        Group by signature, most frequent first
  --show N|ID    Print one capture's full analysis (number from --list, an id
                 such as sanitizer/1358917.00001, or a directory)
  --backtraces   Run gdb on every collected core up front, so crashes group by
                 backtrace (otherwise gdb runs when a capture is opened)
  --no-gdb       Never run gdb
  --help         Show this help

TUI keys: arrows/jk move, enter category/details, g group, r rescan,
n/p next/previous in details, esc/q back (quit from categories), Ctrl-C quit.

TXT;
    }
}
