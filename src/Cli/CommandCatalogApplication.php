<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

final class CommandCatalogApplication
{
    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     */
    public function __construct($output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse($arguments, ['commands'], ['help']);
            if ($options->has('help')) {
                $this->write(self::HELP . "\n");
                return 0;
            }

            $configuration = new RunConfiguration(
                commands: $options->csv('commands'),
                raw: true,
                includeBlocking: true,
                includeLocal: true,
                includeAdmin: true,
                includeFlush: true,
                includeCrashing: true,
                includeStateful: true,
            );
            $registry = (new CommandFilter())->apply(new Registry(), $configuration);

            $this->write($this->table($registry));
            return 0;
        } catch (\Throwable $throwable) {
            $this->write('phpredis-commands: ' . $throwable->getMessage() . "\n", true);
            return 1;
        }
    }

    private function table(Registry $registry): string
    {
        $rows = [];
        $commands = $registry->commands();
        ksort($commands);

        foreach ($commands as $command) {
            $rows[] = [
                $command->name(),
                $command->type(),
                $this->categories($command),
                $this->surfaces($command),
            ];
        }

        $headings = ['COMMAND', 'TYPE', 'CATEGORIES', 'SURFACES'];
        $widths = array_map('strlen', $headings);
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index], strlen($value));
            }
        }

        $lines = [
            $this->row($headings, $widths),
            $this->row(array_map(static fn (int $width): string => str_repeat('-', $width), $widths), $widths),
        ];
        foreach ($rows as $row) {
            $lines[] = $this->row($row, $widths);
        }
        $lines[] = '';
        $lines[] = sprintf('%d command%s', count($rows), count($rows) === 1 ? '' : 's');

        return implode("\n", $lines) . "\n";
    }

    private function categories(Command $command): string
    {
        $names = Command::flagNames($command->flags());

        return $names === [] ? '-' : implode(',', $names);
    }

    private function surfaces(Command $command): string
    {
        $surfaces = [];
        if ($command instanceof FuzzInterface) {
            $surfaces[] = 'client';
        }
        if ($command instanceof FuzzRawInterface) {
            $surfaces[] = 'raw';
        }
        if ($command instanceof ProxyInterface) {
            $surfaces[] = 'proxy';
        }

        return $surfaces === [] ? '-' : implode(',', $surfaces);
    }

    /**
     * @param list<string> $values
     * @param list<int> $widths
     */
    private function row(array $values, array $widths): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = str_pad($value, $widths[$index]);
        }

        return rtrim(implode('  ', $cells));
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? $this->error : $this->output, $message);
    }

    private const HELP = <<<'HELP'
phpredis-commands - list commands covered by the fuzzer catalog

Usage:
  phpredis-commands [options]

Options:
  --commands=PATTERN,...  Include/exclude command names, globs, or @categories
  --help                  Show this help

Filters are case-insensitive. Positive filters are ORed before negative filters
are removed. Examples: get, 'get*,-getex', '@read,-@scan'. All safety
categories are visible; this command only inspects the catalog and never
connects to or runs commands against Redis.

Surfaces identify normal client-method fuzzing, raw protocol fuzzing, and
source-server proxy sampling.
HELP;
}
