<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final class ValueSummary
{
    public static function type(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => get_debug_type($value),
        };
    }

    /** @return array<string, mixed> */
    public static function summarize(mixed $value, int $depth = 0): array
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return ['type' => get_debug_type($value), 'value' => $value];
        }
        if (is_float($value)) {
            return [
                'type' => 'float',
                'value' => is_finite($value) ? $value : (string) $value,
            ];
        }
        if (is_string($value)) {
            $previewLength = 96;

            return [
                'type' => 'string',
                'length' => strlen($value),
                'preview_base64' => base64_encode(substr($value, 0, $previewLength)),
                'truncated' => strlen($value) > $previewLength,
                'sha256' => hash('sha256', $value),
            ];
        }
        if (is_array($value)) {
            $summary = [
                'type' => 'array',
                'count' => count($value),
                'truncated' => count($value) > 16 || $depth >= 3,
                'entries' => [],
            ];
            if ($depth >= 3) {
                return $summary;
            }
            $index = 0;
            foreach ($value as $key => $item) {
                if ($index++ >= 16) {
                    break;
                }
                $summary['entries'][] = [
                    'key' => $key,
                    'value' => self::summarize($item, $depth + 1),
                ];
            }

            return $summary;
        }
        if (is_object($value)) {
            return ['type' => 'object', 'class' => $value::class];
        }
        if (is_resource($value)) {
            return ['type' => 'resource', 'resource_type' => get_resource_type($value)];
        }

        return ['type' => get_debug_type($value)];
    }
}
