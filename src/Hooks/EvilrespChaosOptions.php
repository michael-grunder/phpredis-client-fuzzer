<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

/** Options for the server's CHAOS preset; manual controls remain environment-driven. */
final readonly class EvilrespChaosOptions
{
    public bool $enabled;
    public int $minimum;
    public int $maximum;
    public string $hash;

    /** @param array<string, string> $arguments */
    public function __construct(array $arguments = [])
    {
        foreach ($arguments as $key => $value) {
            if (!in_array($key, ['mode', 'chaos_min', 'chaos_max', 'hash'], true)) {
                throw new \InvalidArgumentException("Unknown evilresp hook argument: {$key}");
            }
        }
        $mode = strtolower($arguments['mode'] ?? 'manual');
        if (!in_array($mode, ['manual', 'chaos'], true)) {
            throw new \InvalidArgumentException('evilresp.mode must be manual or chaos');
        }
        $this->enabled = $mode === 'chaos';
        if (!$this->enabled && array_diff(array_keys($arguments), ['mode']) !== []) {
            throw new \InvalidArgumentException('evilresp chaos_min, chaos_max and hash require mode=chaos');
        }
        $this->minimum = self::temperature($arguments['chaos_min'] ?? '5', 'chaos_min');
        $this->maximum = self::temperature($arguments['chaos_max'] ?? '75', 'chaos_max');
        if ($this->minimum > $this->maximum) {
            throw new \InvalidArgumentException('evilresp.chaos_min must not exceed chaos_max');
        }
        $this->hash = strtoupper($arguments['hash'] ?? 'BLAKE3');
        if (!in_array($this->hash, ['BLAKE3', 'TLSH'], true)) {
            throw new \InvalidArgumentException('evilresp.hash must be BLAKE3 or TLSH');
        }
    }

    /** Temperatures use hundredths, like the existing hook's percentages. */
    private static function temperature(string $value, string $name): int
    {
        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', $value) !== 1 || (float) $value > 100) {
            throw new \InvalidArgumentException("evilresp.{$name} must be in 0..100 with at most two decimal places");
        }

        return (int) round((float) $value * 100);
    }

    public function acceptsFingerprint(mixed $reply): bool
    {
        return is_string($reply) && ($this->hash === 'BLAKE3'
            ? preg_match('/\A[0-9a-f]{64}\z/i', $reply) === 1
            : ($reply === 'TNULL' || preg_match('/\A(?:T1)?[0-9a-f]{70}\z/i', $reply) === 1));
    }
}
