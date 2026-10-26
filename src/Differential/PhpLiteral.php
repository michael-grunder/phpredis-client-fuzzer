<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * PHP source literals for generated values, used to hard-code testcases in
 * standalone reproducers. Unlike var_export() it escapes binary bytes, and it
 * spells the values PHP has no plain literal for (NAN, INF, -0.0,
 * PHP_INT_MIN) so the reproducer passes exactly what the fuzzer passed.
 */
final class PhpLiteral
{
    public static function export(mixed $value, int $indent = 0): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return $value === PHP_INT_MIN ? 'PHP_INT_MIN' : (string) $value;
        }
        if (is_float($value)) {
            return match (true) {
                is_nan($value) => 'NAN',
                is_infinite($value) => $value > 0 ? 'INF' : '-INF',
                default => DiffRuntime::floatText($value),
            };
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_array($value)) {
            return self::array($value, $indent);
        }

        throw new \InvalidArgumentException('cannot export ' . get_debug_type($value));
    }

    /** @param array<mixed> $value */
    private static function array(array $value, int $indent): string
    {
        if ($value === []) {
            return '[]';
        }

        $list = array_is_list($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = ($list ? '' : self::export($key) . ' => ') . self::export($item, $indent + 1);
        }

        $inline = '[' . implode(', ', $parts) . ']';
        if (strlen($inline) <= 100 && !str_contains($inline, "\n")) {
            return $inline;
        }

        $pad = str_repeat('    ', $indent + 1);
        return "[\n" . $pad . implode(",\n" . $pad, $parts) . ",\n" . str_repeat('    ', $indent) . ']';
    }

    private static function string(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) !== 1) {
            return "'" . addcslashes($value, "'\\") . "'";
        }

        // Long runs of one byte (boundary-size values) stay readable.
        if (strlen($value) >= 64 && preg_match('/\A(.)\1*\z/s', $value) === 1) {
            return 'str_repeat(' . self::string($value[0]) . ', ' . strlen($value) . ')';
        }

        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $ord = ord($char);
            $out .= match (true) {
                $char === '"' => '\\"',
                $char === '\\' => '\\\\',
                $char === '$' => '\\$',
                $char === "\n" => '\\n',
                $char === "\r" => '\\r',
                $char === "\t" => '\\t',
                $ord < 0x20 || $ord > 0x7e => sprintf('\\x%02x', $ord),
                default => $char,
            };
        }

        return '"' . $out . '"';
    }
}
