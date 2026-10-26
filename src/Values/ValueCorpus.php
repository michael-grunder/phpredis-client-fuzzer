<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

final class ValueCorpus
{
    public const STRING_VARIANTS = ['empty', 'nul', 'crlf', 'high-bytes', 'invalid-utf8', 'repeated', 'binary', 'numeric'];
    public const COMPLEX_VARIANTS = [
        'integer', 'float', 'null', 'boolean', 'empty-array', 'sparse', 'associative',
        'nested', 'references', 'copy-on-write', 'stringable', 'serialized', 'wakeup',
        'throw-string', 'throw-serialize', 'throw-unserialize', 'throw-wakeup',
    ];

    public function __construct(public readonly int $maxBytes = 4097)
    {
        if ($maxBytes < 1 || $maxBytes > 1_048_576) {
            throw new \InvalidArgumentException('maxValueBytes must be between 1 and 1048576');
        }
    }

    public function string(?string $variant = null): string
    {
        $variant ??= self::STRING_VARIANTS[array_rand(self::STRING_VARIANTS)];
        $lengths = array_values(array_filter(
            [0, 1, 31, 32, 33, 255, 256, 257, 511, 512, 513, 4095, 4096, 4097],
            fn (int $size): bool => $size <= $this->maxBytes,
        ));
        $lengths[] = $this->maxBytes;
        $length = $lengths[array_rand($lengths)];
        $value = match ($variant) {
            'empty' => '',
            'nul' => "\0",
            'crlf' => "\r\n\0\r\n",
            'high-bytes' => "\x80\xff\xfe",
            'invalid-utf8' => "\xc3\x28\xed\xa0\x80",
            'repeated' => str_repeat('A', $length),
            'binary' => $this->bytes($length),
            'numeric' => ['0', '-0', '00', '01', '-1', '1e3', '+1.25', '9223372036854775808', '18446744073709551615'][rand(0, 8)],
            default => throw new \InvalidArgumentException('Unknown string corpus variant'),
        };

        return substr($value, 0, $this->maxBytes);
    }

    private function bytes(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    public function value(bool $serialized, bool $allowMutations = false, ?string $variant = null): mixed
    {
        if ($variant === null) {
            // Throwing callbacks are rare; ordinary callbacks remain part of normal coverage.
            $variants = $serialized
                ? [...self::STRING_VARIANTS, ...array_slice(self::COMPLEX_VARIANTS, 0, 13)]
                : [...self::STRING_VARIANTS, 'integer', 'float', 'null', 'boolean', 'stringable'];
            if ($serialized && rand(0, 15) === 0) {
                $variants = ['throw-string', 'throw-serialize', 'throw-unserialize', 'throw-wakeup'];
            }
            if ($serialized && $allowMutations && rand(0, 15) === 0) {
                $variants = ['mutating'];
            }
            $variant = $variants[array_rand($variants)];
        }
        if (in_array($variant, self::STRING_VARIANTS, true)) {
            return $this->string($variant);
        }
        // Bound aggregate payload size as well as depth and element count.
        $payload = substr($this->string(), 0, min(128, $this->maxBytes));
        $shared = [$payload];
        $copy = $shared;
        $shared[] = 'changed';
        return match ($variant) {
            'integer' => [0, -1, PHP_INT_MIN, PHP_INT_MAX, -2147483648, 2147483647, 2147483648][rand(0, 6)],
            'float' => [0.0, -0.0, 1.25, INF, -INF, NAN][rand(0, 5)],
            'null' => null,
            'boolean' => (bool) rand(0, 1),
            'empty-array' => [],
            'sparse' => [0 => $payload, 7 => null, 100 => -1],
            'associative' => ['0' => 0, '01' => $payload, '-1' => -1, '' => null, "a\0b" => true],
            'nested' => ['outer' => [[], ['inner' => $payload]]],
            'references' => [&$payload, &$payload, ['alias' => &$payload]],
            'copy-on-write' => [$copy, $shared],
            'stringable' => new StringValue($payload),
            'serialized' => new SerializedValue($payload),
            'wakeup' => new WakeupValue($payload),
            'throw-string' => new StringValue($payload, true),
            'throw-serialize', 'throw-unserialize' => new SerializedValue($payload, $variant),
            'throw-wakeup' => new WakeupValue($payload, true),
            'mutating' => $allowMutations
                ? new MutatingValue($payload, ['close', 'serializer', 'delete'][rand(0, 2)])
                : throw new \InvalidArgumentException('Deserialization mutations require explicit opt-in'),
            default => throw new \InvalidArgumentException('Unknown value corpus variant'),
        };
    }
}
