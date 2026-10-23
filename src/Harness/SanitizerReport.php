<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/** ASAN, LSan, or UBSan evidence; the complete report remains in stderr.log. */
final class SanitizerReport
{
    public function __construct(public readonly string $message)
    {
    }

    public static function scan(string $path): ?self
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }
        try {
            $continuation = false;
            while (($line = fgets($handle, 16384)) !== false) {
                $skip = $continuation;
                $continuation = !str_ends_with($line, "\n");
                if (!$skip && preg_match(
                    '/^(?:==\d+==\s*)?(?:ERROR: (?:AddressSanitizer|LeakSanitizer):'
                    . '|SUMMARY: (?:AddressSanitizer|LeakSanitizer|UndefinedBehaviorSanitizer):'
                    . '|AddressSanitizer:DEADLYSIGNAL)'
                    . '|^\S.*:\d+(?::\d+)?: runtime error: /',
                    $line,
                ) === 1) {
                    return new self(trim($line));
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
