<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\FuzzResult;

final class ResultFormatter
{
    public function format(FuzzResult $result, OutputMode $mode): string
    {
        if ($mode === OutputMode::Json) {
            return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        }

        $output = $this->summary($result);
        if ($mode === OutputMode::Detailed) {
            $output .= "\n" . $this->commandTable($result);
            $details = $this->issueDetails($result);
            if ($details !== '') {
                $output .= "\n" . $details;
            }
        }

        return $output;
    }

    private function summary(FuzzResult $result): string
    {
        [$exceptionOccurrences, $uniqueExceptions] = $this->exceptionCounts($result);

        return implode("\n", [
            'Fuzz run summary',
            sprintf('  %-25s %d', 'Seed:', $result->seed),
            sprintf('  %-25s %d', 'Commands processed:', $result->steps),
            sprintf('  %-25s %d', 'Unique commands executed:', count($result->commands)),
            sprintf('  %-25s %d', 'Selected commands:', count($result->selectedCommands)),
            sprintf('  %-25s %d', 'Cross-slot steps:', $result->crossSlotSteps),
            sprintf('  %-25s %.6f seconds', 'Elapsed:', $result->elapsedSeconds),
            sprintf(
                '  %-25s %d (%d unique)',
                'Warnings:',
                array_sum($result->warnings),
                count($result->warnings),
            ),
            sprintf(
                '  %-25s %d (%d unique)',
                'Exceptions:',
                $exceptionOccurrences,
                $uniqueExceptions,
            ),
            '',
        ]);
    }

    private function commandTable(FuzzResult $result): string
    {
        $rows = [];
        $commands = $result->commands;
        ksort($commands);

        foreach ($commands as $command => $statistics) {
            $replies = [];
            foreach ($statistics['replies'] as $type => $count) {
                $replies[] = "{$type}: {$count}";
            }
            $rows[] = [
                $command,
                (string) $statistics['count'],
                $replies === [] ? '-' : implode(', ', $replies),
                (string) array_sum($result->commandWarnings[$command] ?? []),
                (string) array_sum($statistics['exceptions']),
            ];
        }

        $headings = ['COMMAND', 'EXECUTED', 'REPLIES', 'WARNINGS', 'EXCEPTIONS'];
        $widths = array_map('strlen', $headings);
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index], strlen($value));
            }
        }

        $lines = ['Per-command results', $this->tableRow($headings, $widths)];
        $lines[] = $this->tableRow(array_map(
            static fn (int $width): string => str_repeat('-', $width),
            $widths,
        ), $widths);
        foreach ($rows as $row) {
            $lines[] = $this->tableRow($row, $widths);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $values
     * @param list<int> $widths
     */
    private function tableRow(array $values, array $widths): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = str_pad($value, $widths[$index]);
        }

        return '  ' . implode('  ', $cells);
    }

    private function issueDetails(FuzzResult $result): string
    {
        $commands = array_unique([
            ...array_keys($result->commandWarnings),
            ...array_keys($result->commands),
        ]);
        sort($commands);

        $lines = [];
        foreach ($commands as $command) {
            $warnings = $result->commandWarnings[$command] ?? [];
            $exceptions = $result->commands[$command]['exceptions'] ?? [];
            if ($warnings === [] && $exceptions === []) {
                continue;
            }

            $lines[] = $lines === [] ? 'Warnings and exceptions' : '';
            $lines[] = "  {$command}";
            foreach ($warnings as $message => $count) {
                $lines[] = "    warning x{$count}: {$message}";
            }
            foreach ($exceptions as $message => $count) {
                $lines[] = "    exception x{$count}: {$message}";
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /** @return array{int, int} */
    private function exceptionCounts(FuzzResult $result): array
    {
        $occurrences = 0;
        $unique = [];
        foreach ($result->commands as $statistics) {
            $occurrences += array_sum($statistics['exceptions']);
            foreach (array_keys($statistics['exceptions']) as $exception) {
                $unique[$exception] = true;
            }
        }

        return [$occurrences, count($unique)];
    }
}
