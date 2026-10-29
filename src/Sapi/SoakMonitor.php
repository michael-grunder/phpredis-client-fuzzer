<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

use PhpTui\Term\Actions;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Line;

/** Tail the server's request journal without retaining the full history in memory. */
final class SoakMonitor
{
    private ?Terminal $terminal = null;
    private ?Display $display = null;
    /** @var resource */
    private $events;
    /** @var list<string> */
    private array $recent = [];
    private int $started = 0;
    private int $completed = 0;
    private float $lastRender = 0;
    private readonly float $since;

    public function __construct(string $directory, private readonly int $history, bool $tui)
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
            if (!is_array($event) || !is_string($event['path'] ?? null) || !is_int($event['pid'] ?? null)) {
                throw new \RuntimeException('invalid request journal');
            }
            if (($event['event'] ?? null) === 'start') {
                $this->started++;
            } else {
                $this->completed++;
            }
            $summary = sprintf('%s pid=%d %s', ($event['event'] ?? null) === 'start' ? 'START' : 'END', $event['pid'], $event['path']);
            if (($event['event'] ?? null) === 'finish') {
                $summary .= ' exit=' . json_encode($event['exit'] ?? null) . ' seconds=' . json_encode($event['elapsed'] ?? null);
            }
            if (($event['event'] ?? null) === 'finish') {
                $this->recent[] = $summary;
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
            $this->display->draw(ParagraphWidget::fromLines(...array_map(Line::fromString(...), [
                $summary, $connection, 'Ctrl+C to stop; scripts, responses and logs are retained.', '', ...$this->recent,
            ])));
        } else {
            echo $summary . "\n";
        }
    }

    public function stop(): void
    {
        $this->terminal?->execute(Actions::alternateScreenDisable(), Actions::cursorShow());
        fclose($this->events);
    }
}
