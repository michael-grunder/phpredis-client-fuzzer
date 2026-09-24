<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Differential;

/**
 * Argument values for generated operations: mostly small, repetitive values
 * that make commands interact (duplicates, numeric strings, shared members),
 * with boundary and malformed values at the configured edge rate.
 */
final class ValueSource
{
    private const VOCABULARY = ['a', 'b', 'c', 'foo', 'bar', 'baz', '0', '1', '2', '10', '-1', '3.5', 'x y', 'hello'];

    private const EDGE_STRINGS = [
        '', "\0", "a\0b", "\0\0\0", "\xff\xfe", "\x80", "\r\n", ' ', "\t", 'é', "\u{1F600}",
        '0', '-0', '00', '+1', ' 1', '1 ', '1e3', '0x10', '3.0', '.5', '5.', '1e400',
        '9223372036854775807', '9223372036854775808', '-9223372036854775808', '-9223372036854775809',
        'inf', '-inf', '+inf', 'nan', 'NaN', '(1', '[a', '-', '+',
    ];

    private const EDGE_INTS = [0, -1, 1, PHP_INT_MAX, PHP_INT_MIN, 2147483647, -2147483648, 4294967296, -4294967296];

    private const EDGE_FLOATS = [INF, -INF, NAN, -0.0, 0.0, 1e308, -1e308, 5e-324, 0.1, 1e-10, 1.7976931348623157e308, 9007199254740993.0];

    public function __construct(
        private readonly Rng $rng,
        private readonly float $edgeChance,
        private readonly int $maxValueBytes,
    ) {
    }

    public function edge(): bool
    {
        return $this->rng->chance($this->edgeChance);
    }

    /** A string value, as stored by Redis. */
    public function string(): string
    {
        if ($this->edge()) {
            return match ($this->rng->int(0, 5)) {
                0 => $this->rng->bytes($this->rng->int(1, 16)),
                1 => str_repeat('x', $this->maxValueBytes),
                2 => str_repeat($this->rng->pick(['y', "\0", "\xff"]), $this->rng->int(1, min(512, $this->maxValueBytes))),
                3 => (string) $this->rng->pick(self::EDGE_INTS),
                default => $this->rng->pick(self::EDGE_STRINGS),
            };
        }

        return $this->rng->chance(0.7)
            ? $this->rng->pick(self::VOCABULARY)
            : bin2hex($this->rng->bytes($this->rng->int(1, 6)));
    }

    /**
     * A PHP value passed to a client as a stored value. Non-string values
     * exercise each client's conversion or serialization, which is where the
     * two implementations are most likely to disagree.
     */
    public function php(bool $serializing): mixed
    {
        if (!$this->rng->chance($serializing ? 0.35 : $this->edgeChance / 2)) {
            return $this->string();
        }

        return match ($this->rng->int(0, 6)) {
            0 => $this->int(),
            1 => $this->float(),
            2 => $this->rng->pick([true, false]),
            3 => null,
            4 => [$this->string(), $this->int()],
            5 => ['k' => $this->string(), 'n' => $this->float()],
            default => $this->rng->pick([1.0, 0.5, -2.25, 100.0, 1e20, 0.30000000000000004]),
        };
    }

    public function int(): int
    {
        if ($this->edge()) {
            return $this->rng->pick(self::EDGE_INTS);
        }

        return $this->rng->int(-5, 20);
    }

    public function float(): float
    {
        if ($this->edge()) {
            return $this->rng->pick(self::EDGE_FLOATS);
        }

        return $this->rng->pick([0.5, 1.5, -2.25, 3.0, 10.0, 0.1, 2.5e-3, 1e6]) * $this->rng->pick([1, -1]);
    }

    /** A sorted-set score argument: usually a float, sometimes an int or numeric string. */
    public function score(): float|int|string
    {
        if ($this->edge()) {
            return $this->rng->pick([...self::EDGE_FLOATS, '+inf', '-inf', 'nan', 'abc', '1e3', '', PHP_INT_MAX]);
        }

        return match ($this->rng->int(0, 3)) {
            0 => $this->rng->int(-3, 10),
            1 => (string) $this->rng->int(-3, 10),
            default => $this->rng->pick([0.0, 0.5, 1.0, 1.5, 2.0, -1.0, 3.25]),
        };
    }

    /** A score range bound, as understood by ZRANGEBYSCORE and friends. */
    public function scoreBound(): string
    {
        if ($this->edge()) {
            return $this->rng->pick(['nan', 'abc', '', '((1', '1e400', '-0', '(inf', '0x1']);
        }

        return $this->rng->pick(['-inf', '+inf', '0', '1', '(1', '2.5', '(0', '-1', '10']);
    }

    /** A lexicographic range bound, as understood by ZRANGEBYLEX. */
    public function lexBound(): string
    {
        if ($this->edge()) {
            return $this->rng->pick(['a', '', '(', '[', "[\0", "(\xff"]);
        }

        return $this->rng->pick(['-', '+', '[a', '(a', '[foo', '(c', '[1', '[']);
    }

    /** An index relative to a collection of about $length elements. */
    public function index(int $length): int
    {
        if ($this->edge()) {
            return $this->rng->pick([PHP_INT_MAX, PHP_INT_MIN, 2147483647, -2147483648, $length, -$length - 1]);
        }

        return $this->rng->int(-$length - 2, $length + 2);
    }

    /** A count argument: small, sometimes zero or negative. */
    public function count(): int
    {
        if ($this->edge()) {
            return $this->rng->pick([0, -1, -100, PHP_INT_MAX, PHP_INT_MIN, 1000]);
        }

        return $this->rng->int(1, 5);
    }

    /**
     * A relative TTL that cannot expire while a case runs. Zero and negative
     * values delete immediately, which is equally deterministic.
     */
    public function ttlSeconds(): int
    {
        if ($this->edge()) {
            return $this->rng->pick([0, -1, PHP_INT_MAX, 2147483648, 9223372036854775]);
        }

        return $this->rng->int(1_000, 100_000);
    }

    /** An absolute unix time: far in the past or far in the future, never "now". */
    public function unixTime(): int
    {
        return $this->rng->pick([1, 1_000_000_000, 4_102_444_800, 4_102_444_800 + $this->rng->int(0, 1000)]);
    }

    public function rng(): Rng
    {
        return $this->rng;
    }
}
