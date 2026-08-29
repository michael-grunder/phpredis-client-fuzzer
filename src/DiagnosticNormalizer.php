<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

/**
 * Produces stable diagnostic fingerprints for aggregate reporting.
 *
 * Rules must stay narrow: exact outcome text is the reproduction evidence,
 * while this class removes only values known to vary without changing the
 * underlying failure category.
 */
final class DiagnosticNormalizer
{
    /** @var list<array{pattern: string, replacement: string}> */
    private const RULES = [
        [
            'pattern' => "~(\\bNOGROUP No such key )'[^']*'( or consumer group '[^']*')~",
            'replacement' => "$1'<key>'$2",
        ],
        [
            'pattern' => '~( script: )[0-9a-f]{40}(, on @user_script:)\d+~i',
            'replacement' => '$1<sha>$2<line>',
        ],
        [
            'pattern' => '~\((RELAY_ERR_[A-Z_]+); ([^)]+):\d+\)$~',
            'replacement' => '($1; $2:<line>)',
        ],
        [
            'pattern' => '~ in (?:.*[/\\\\])?([^/\\\\]+) on line \d+$~',
            'replacement' => ' in $1 on line <line>',
        ],
    ];

    public static function normalize(string $diagnostic): string
    {
        foreach (self::RULES as $rule) {
            $normalized = preg_replace(
                $rule['pattern'],
                $rule['replacement'],
                $diagnostic,
            );
            if ($normalized === null) {
                throw new \LogicException('Invalid diagnostic normalization pattern');
            }
            $diagnostic = $normalized;
        }

        return $diagnostic;
    }

    /**
     * @param array<string, int> $diagnostics
     * @return array<string, int>
     */
    public static function aggregate(array $diagnostics): array
    {
        $aggregated = [];
        foreach ($diagnostics as $diagnostic => $count) {
            $normalized = self::normalize($diagnostic);
            $aggregated[$normalized] = ($aggregated[$normalized] ?? 0) + $count;
        }

        return $aggregated;
    }
}
