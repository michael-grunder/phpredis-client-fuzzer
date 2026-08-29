<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\DiagnosticNormalizer;
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
            $output .= "\n" . $this->problematicCommands($result);
            $details = $this->issueDetails($result);
            if ($details !== '') {
                $output .= "\n" . $details;
            }
            $differential = $this->differentialDetails($result);
            if ($differential !== '') {
                $output .= "\n" . $differential;
            }
        }

        return $output;
    }

    private function problematicCommands(FuzzResult $result): string
    {
        $lines = ['Problematic commands (server-supported commands with only false replies)'];
        if ($result->problematicCommands === []) {
            $lines[] = '  None';
        } else {
            foreach ($result->problematicCommands as $command => $statistics) {
                $lines[] = sprintf(
                    '  %s: %d executed, %d false %s',
                    $command,
                    $statistics['executions'],
                    $statistics['false_replies'],
                    $statistics['false_replies'] === 1 ? 'reply' : 'replies',
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function summary(FuzzResult $result): string
    {
        $warnings = DiagnosticNormalizer::aggregate($result->warnings);
        [$exceptionOccurrences, $uniqueExceptions] = $this->exceptionCounts($result);
        [$redisErrorOccurrences, $uniqueRedisErrors] = $this->redisErrorCounts($result);

        $lines = [
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
                array_sum($warnings),
                count($warnings),
            ),
            sprintf(
                '  %-25s %d (%d unique)',
                'Redis errors:',
                $redisErrorOccurrences,
                $uniqueRedisErrors,
            ),
            sprintf(
                '  %-25s %d (%d unique)',
                'Exceptions:',
                $exceptionOccurrences,
                $uniqueExceptions,
            ),
        ];
        if (($result->configuration['differential'] ?? false) === true) {
            $counts = $this->differentialCounts($result);
            $lines[] = sprintf('  %-25s %d', 'Differential checks:', array_sum($counts));
            $lines[] = sprintf('  %-25s %d', 'Differential converged:', $counts['converged']);
            $lines[] = sprintf('  %-25s %d', 'Differential divergent:', $counts['divergent']);
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function commandTable(FuzzResult $result): string
    {
        $rows = [];
        $commands = $result->commands;
        $redisErrors = $this->redisErrorsByCommand($result);
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
                (string) array_sum($redisErrors[$command] ?? []),
                (string) array_sum($statistics['exceptions']),
            ];
        }

        $headings = ['COMMAND', 'EXECUTED', 'REPLIES', 'WARNINGS', 'REDIS ERRORS', 'EXCEPTIONS'];
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
        $redisErrors = $this->redisErrorsByCommand($result);
        $commands = array_unique([
            ...array_keys($result->commandWarnings),
            ...array_keys($redisErrors),
            ...array_keys($result->commands),
        ]);
        sort($commands);

        $lines = [];
        foreach ($commands as $command) {
            $warnings = DiagnosticNormalizer::aggregate($result->commandWarnings[$command] ?? []);
            $commandRedisErrors = $redisErrors[$command] ?? [];
            $exceptions = DiagnosticNormalizer::aggregate(
                $result->commands[$command]['exceptions'] ?? [],
            );
            if ($warnings === [] && $commandRedisErrors === [] && $exceptions === []) {
                continue;
            }

            $lines[] = $lines === [] ? 'Warnings, Redis errors, and exceptions' : '';
            $lines[] = "  {$command}";
            foreach ($warnings as $message => $count) {
                $lines[] = "    warning x{$count}: {$message}";
            }
            foreach ($commandRedisErrors as $message => $count) {
                $lines[] = "    Redis error x{$count}: {$message}";
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
                $unique[DiagnosticNormalizer::normalize($exception)] = true;
            }
        }

        return [$occurrences, count($unique)];
    }

    /** @return array{int, int} */
    private function redisErrorCounts(FuzzResult $result): array
    {
        $occurrences = 0;
        $unique = [];
        foreach ($result->outcomes as $outcome) {
            $occurrences += count($outcome->redisErrors);
            foreach ($outcome->redisErrors as $error) {
                $unique[DiagnosticNormalizer::normalize($error)] = true;
            }
        }

        return [$occurrences, count($unique)];
    }

    /** @return array<string, array<string, int>> */
    private function redisErrorsByCommand(FuzzResult $result): array
    {
        $errors = [];
        foreach ($result->outcomes as $outcome) {
            foreach ($outcome->redisErrors as $error) {
                $error = DiagnosticNormalizer::normalize($error);
                $errors[$outcome->command] ??= [];
                $errors[$outcome->command][$error] =
                    ($errors[$outcome->command][$error] ?? 0) + 1;
            }
        }

        return $errors;
    }

    /** @return array{matched: int, converged: int, divergent: int} */
    private function differentialCounts(FuzzResult $result): array
    {
        $counts = ['matched' => 0, 'converged' => 0, 'divergent' => 0];
        foreach ($result->differentialOutcomes as $outcome) {
            match ($outcome->status) {
                'matched' => $counts['matched']++,
                'converged' => $counts['converged']++,
                'divergent' => $counts['divergent']++,
                default => throw new \UnexpectedValueException(
                    'Unknown differential status: ' . $outcome->status,
                ),
            };
        }

        return $counts;
    }

    private function differentialDetails(FuzzResult $result): string
    {
        if (($result->configuration['differential'] ?? false) !== true) {
            return '';
        }

        $counts = $this->differentialCounts($result);
        $lines = [
            'Differential oracle',
            sprintf(
                '  %d matched, %d converged within tolerance, %d divergent',
                $counts['matched'],
                $counts['converged'],
                $counts['divergent'],
            ),
        ];
        foreach ($result->differentialOutcomes as $outcome) {
            if ($outcome->status === 'matched') {
                continue;
            }
            $differences = $outcome->finalDifferences === []
                ? implode(', ', $outcome->initialDifferences)
                : implode(', ', $outcome->finalDifferences);
            $timing = $outcome->convergenceSeconds === null
                ? ''
                : sprintf(', converged in %.6f seconds', $outcome->convergenceSeconds);
            $lines[] = sprintf(
                '  #%d %s: %s (%s, %d subject attempts%s)',
                $outcome->sequence,
                $outcome->command,
                $outcome->status,
                $differences === '' ? 'no remaining differences' : $differences,
                $outcome->subjectAttempts,
                $timing,
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
