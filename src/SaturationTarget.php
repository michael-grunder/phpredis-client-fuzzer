<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Relay\Relay;

/**
 * A CLI saturation target that resolves to the concrete byte count consumed by
 * RunConfiguration. Percentage targets are relative to Relay's process-wide
 * cache memory total.
 */
final readonly class SaturationTarget
{
    private function __construct(
        private ?int $bytes,
        private ?float $percentage,
    ) {
    }

    public static function parse(string $value): self
    {
        if (str_ends_with($value, '%')) {
            if (preg_match('/\A([0-9]+(?:\.[0-9]+)?)%\z/', $value, $matches) !== 1) {
                throw new \InvalidArgumentException(
                    '--saturate-target percentage must look like 10% or 95.2%',
                );
            }

            $percentage = (float) $matches[1];
            if (!is_finite($percentage) || $percentage <= 0.0 || $percentage > 100.0) {
                throw new \InvalidArgumentException(
                    '--saturate-target percentage must be greater than 0 and at most 100',
                );
            }

            return new self(null, $percentage);
        }

        if (preg_match('/\A([0-9]+)([kmgt]?)\z/i', $value, $matches) !== 1) {
            throw new \InvalidArgumentException(
                '--saturate-target must be a byte size such as 100, 100k, or 100m, '
                . 'or a percentage such as 95.2%',
            );
        }

        $bytes = filter_var($matches[1], FILTER_VALIDATE_INT);
        if (!is_int($bytes)) {
            throw new \InvalidArgumentException('--saturate-target byte size is too large');
        }

        $multiplier = match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => 1,
        };
        if ($bytes > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new \InvalidArgumentException('--saturate-target byte size is too large');
        }

        return new self($bytes * $multiplier, null);
    }

    public function isPercentage(): bool
    {
        return $this->percentage !== null;
    }

    /** @param (\Closure(): array<mixed>)|null $statsReader */
    public function resolve(?\Closure $statsReader = null): int
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }

        $statsReader ??= static fn (): array => Relay::stats();
        $stats = $statsReader();
        $memory = $stats['memory'] ?? null;
        $total = is_array($memory) ? ($memory['total'] ?? null) : null;
        if (!is_int($total) || $total < 1) {
            throw new \UnexpectedValueException(
                'Relay stats memory.total must be a positive integer',
            );
        }

        if ($this->percentage === null) {
            throw new \LogicException('Saturation target has no byte or percentage value');
        }

        return max(1, (int) round($total * ($this->percentage / 100.0)));
    }
}
