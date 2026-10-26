<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * Lossless JSON encoding for generated PHP values. Plain JSON cannot carry
 * binary strings, NAN, INF, -0.0, or the difference between a list and a
 * map, all of which the fuzzer generates on purpose. Anything JSON cannot
 * represent exactly is wrapped in a single-key tag object:
 *
 *   {"$b64": "..."}      a string that is not valid UTF-8
 *   {"$float": "NAN"}    a float (always tagged, so 1.0 stays a float)
 *   {"$map": [[k, v]]}   an array that is not a list
 */
final class ValueCodec
{
    public static function encode(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : ['$b64' => base64_encode($value)];
        }
        if (is_float($value)) {
            return ['$float' => DiffRuntime::floatText($value)];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::encode(...), $value);
            }
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = [self::encode($key), self::encode($item)];
            }
            return ['$map' => $pairs];
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('cannot encode ' . get_debug_type($value));
    }

    public static function decode(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (count($value) === 1 && !array_is_list($value)) {
            $tag = array_key_first($value);
            $body = $value[$tag];
            if ($tag === '$b64' && is_string($body)) {
                $decoded = base64_decode($body, true);
                if ($decoded === false) {
                    throw new \InvalidArgumentException('invalid $b64 value');
                }
                return $decoded;
            }
            if ($tag === '$float' && is_string($body)) {
                return self::float($body);
            }
            if ($tag === '$map' && is_array($body)) {
                $map = [];
                foreach ($body as $pair) {
                    if (!is_array($pair) || count($pair) !== 2) {
                        throw new \InvalidArgumentException('invalid $map pair');
                    }
                    $key = self::decode($pair[0]);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \InvalidArgumentException('invalid $map key');
                    }
                    $map[$key] = self::decode($pair[1]);
                }
                return $map;
            }
        }

        return array_map(self::decode(...), $value);
    }

    public static function float(string $text): float
    {
        return match ($text) {
            'NAN' => NAN,
            'INF' => INF,
            '-INF' => -INF,
            '-0.0' => -0.0,
            default => is_numeric($text)
                ? (float) $text
                : throw new \InvalidArgumentException("invalid float {$text}"),
        };
    }
}
