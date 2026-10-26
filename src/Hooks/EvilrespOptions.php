<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Hooks;

use InvalidArgumentException;
use Random\Randomizer;

/** @internal Configuration of the optional evilresp example hook. */
final class EvilrespOptions
{
    /** @var array<string, string> */
    private array $settings;

    /** @var array<string, array{int, int}> */
    private array $probabilities = [];

    /** @var non-empty-list<string> */
    private array $faults;

    /** @var non-empty-list<string> */
    private array $faultPoints;

    public function __construct()
    {
        $this->settings = [
            'strategy' => self::choice('STRATEGY', ['PRESERVE', 'REPLACE'], 'PRESERVE'),
            'mutations' => self::choice('MUTATIONS', ['ONE', 'MANY'], 'MANY'),
            'generator_protocol' => self::choice('GENERATOR_PROTOCOL', ['RESP2', 'RESP3'], 'RESP2'),
            'generator_corpus' => self::choice('GENERATOR_CORPUS', ['BOUNDARY', 'RANDOM'], 'BOUNDARY'),
            'generator_violations' => self::choice('GENERATOR_VIOLATIONS', ['OFF', 'ON'], 'OFF'),
            'framing' => self::choice('FRAMING', ['AUTO', 'OFF', 'LENGTH'], 'AUTO'),
        ];
        if ($this->settings['framing'] === 'LENGTH') {
            $this->probabilities['framing_probability'] = self::percentage('FRAMING_PROBABILITY', '100');
            $this->settings['framing_target'] = self::target(self::env('FRAMING_TARGET') ?? 'ANY');
            $this->settings['framing_kind'] = self::choice(
                'FRAMING_KIND', ['RANDOM', 'SHORTER', 'LONGER', 'NEGATIVE', 'BOUNDARY', 'OVERFLOW'], 'RANDOM',
            );
        } else {
            self::rejectOptions(['FRAMING_PROBABILITY', 'FRAMING_TARGET', 'FRAMING_KIND'], 'EVILRESP_FRAMING=LENGTH');
        }

        if (self::env('TRANSPORT') !== null) {
            self::choice('TRANSPORT', ['OFF'], 'OFF');
            self::rejectOptions(
                ['TRANSPORT_TRUNCATE', 'TRANSPORT_EXTRA', 'TRANSPORT_CHUNKS', 'TRANSPORT_PROBABILITY',
                    'TRANSPORT_FAULT', 'TRANSPORT_AT', 'TRANSPORT_DURATION'],
                'EVILRESP_TRANSPORT to be unset',
            );
        }
        $truncate = strtoupper(self::env('TRANSPORT_TRUNCATE') ?? 'OFF');
        if (!in_array($truncate, ['OFF', 'RANDOM'], true)) {
            $truncate = self::unsigned('TRANSPORT_TRUNCATE', $truncate);
        }
        // An explicit legacy truncation plan keeps its existing behavior.
        $this->faults = self::candidates(
            'TRANSPORT_FAULT', ['OFF', 'CLOSE', 'RESET', 'STALL'],
            self::env('TRANSPORT') !== null || $truncate !== 'OFF' ? 'OFF' : 'OFF,CLOSE,RESET',
        );
        $hasFaults = array_diff($this->faults, ['OFF']) !== [];
        if ($truncate !== 'OFF' && $hasFaults) {
            throw new InvalidArgumentException('EVILRESP_TRANSPORT_FAULT requires EVILRESP_TRANSPORT_TRUNCATE=OFF; use EVILRESP_TRANSPORT_AT for offsets');
        }
        $this->faultPoints = self::candidates(
            'TRANSPORT_AT', ['BEFORE', 'AFTER', 'REPLY', 'RANDOM'],
            $hasFaults ? 'BEFORE,AFTER,REPLY,RANDOM' : 'AFTER',
            offsets: true,
        );
        $duration = self::unsigned('TRANSPORT_DURATION', self::env('TRANSPORT_DURATION') ?? '10000');
        if ($duration === '0' || self::compareUnsigned($duration, '3600000') > 0) {
            throw new InvalidArgumentException('EVILRESP_TRANSPORT_DURATION must be within 1-3600000 milliseconds');
        }
        if (self::env('TRANSPORT_DURATION') !== null && !in_array('STALL', $this->faults, true)) {
            throw new InvalidArgumentException('EVILRESP_TRANSPORT_DURATION requires STALL in EVILRESP_TRANSPORT_FAULT');
        }
        $extra = self::unsigned('TRANSPORT_EXTRA', self::env('TRANSPORT_EXTRA') ?? '0');
        if (strlen($extra) > 2 || (int) $extra > 16) {
            throw new InvalidArgumentException('EVILRESP_TRANSPORT_EXTRA must be within 0-16');
        }
        $chunks = strtoupper(self::env('TRANSPORT_CHUNKS') ?? 'OFF');
        if (!in_array($chunks, ['OFF', 'RANDOM'], true)) {
            $offsets = explode(',', $chunks);
            if (count($offsets) > 64) {
                throw new InvalidArgumentException('EVILRESP_TRANSPORT_CHUNKS allows at most 64 offsets');
            }
            $previous = '0';
            foreach ($offsets as &$offset) {
                $offset = self::unsigned('TRANSPORT_CHUNKS', trim($offset));
                if (self::compareUnsigned($offset, $previous) <= 0) {
                    throw new InvalidArgumentException('EVILRESP_TRANSPORT_CHUNKS requires strictly increasing positive offsets');
                }
                $previous = $offset;
            }
            unset($offset);
            $chunks = implode(',', $offsets);
        }
        $this->settings += [
            'transport' => $truncate !== 'OFF' || $extra !== '0' || $chunks !== 'OFF' ? 'ON' : 'OFF',
            'transport_truncate' => $truncate,
            'transport_extra' => $extra,
            'transport_chunks' => $chunks,
            'transport_duration' => $duration,
        ];
        $this->probabilities['transport_probability'] = self::percentage('TRANSPORT_PROBABILITY', $hasFaults ? '1-5' : '100');
    }

    /**
     * Draw once per run; re-arming and script replay use these concrete values.
     *
     * @return array<string, string>
     */
    public function draw(Randomizer $random): array
    {
        $settings = $this->settings;
        foreach ($this->probabilities as $name => [$low, $high]) {
            $settings[$name] = number_format($random->getInt($low, $high) / 100, 2, '.', '');
        }
        $settings['transport_fault'] = $this->faults[$random->getInt(0, count($this->faults) - 1)];
        $settings['transport_at'] = $this->faultPoints[$random->getInt(0, count($this->faultPoints) - 1)];
        if ($settings['transport_fault'] !== 'OFF') {
            $settings['transport'] = 'ON';
        }

        return $settings;
    }

    /**
     * Explicitly restore defaults too: MODE RESET preserves these settings.
     *
     * @param array<string, string> $settings
     * @return list<list<string>>
     */
    public static function commands(array $settings): array
    {
        $commands = [
            ['STRATEGY', $settings['strategy']],
            ['MUTATIONS', $settings['mutations']],
            ['GENERATOR', 'PROTOCOL', $settings['generator_protocol'], 'CORPUS', $settings['generator_corpus'],
                'VIOLATIONS', $settings['generator_violations']],
        ];
        $commands[] = $settings['framing'] === 'LENGTH'
            ? ['FRAMING', 'LENGTH', 'PROBABILITY', $settings['framing_probability'],
                'TARGET', $settings['framing_target'], 'KIND', $settings['framing_kind']]
            : ['FRAMING', $settings['framing']];
        $commands[] = $settings['transport'] === 'OFF' && $settings['transport_probability'] === '100.00'
            && $settings['transport_at'] === 'AFTER' && $settings['transport_duration'] === '10000'
            ? ['TRANSPORT', 'OFF']
            : ['TRANSPORT', 'TRUNCATE', $settings['transport_truncate'], 'EXTRA', $settings['transport_extra'],
                'CHUNKS', $settings['transport_chunks'], 'PROBABILITY', $settings['transport_probability'],
                'FAULT', $settings['transport_fault'], 'AT', $settings['transport_at'],
                'DURATION', $settings['transport_duration']];

        return $commands;
    }

    public static function env(string $name): ?string
    {
        $value = getenv('EVILRESP_' . $name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{int, int} Hundredths of a percent. */
    public static function percentage(string $name, string $default): array
    {
        $value = self::env($name) ?? $default;
        $parts = explode('-', $value, 2);
        $bounds = [];
        foreach ($parts as $part) {
            if (!is_numeric($part) || !is_finite((float) $part) || (float) $part < 0 || (float) $part > 100) {
                throw new InvalidArgumentException("EVILRESP_{$name} must be a percentage or MIN-MAX range within 0-100");
            }
            $bounds[] = (int) round((float) $part * 100);
        }
        $low = $bounds[0];
        $high = $bounds[1] ?? $low;

        return [min($low, $high), max($low, $high)];
    }

    /** @param non-empty-list<string> $choices */
    private static function choice(string $name, array $choices, string $default): string
    {
        $value = strtoupper(self::env($name) ?? $default);
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException("EVILRESP_{$name} must be " . implode(', ', $choices));
        }

        return $value;
    }

    /**
     * @param non-empty-list<string> $choices
     * @return non-empty-list<string>
     */
    private static function candidates(string $name, array $choices, string $default, bool $offsets = false): array
    {
        $values = array_map('trim', explode(',', strtoupper(self::env($name) ?? $default)));
        foreach ($values as &$value) {
            if (!in_array($value, $choices, true)) {
                if (!$offsets) {
                    throw new InvalidArgumentException("EVILRESP_{$name} must contain only " . implode(', ', $choices));
                }
                $value = self::unsigned($name, $value);
            }
        }
        unset($value);

        return $values;
    }

    /** @param list<string> $names */
    private static function rejectOptions(array $names, string $requirement): void
    {
        foreach ($names as $name) {
            if (self::env($name) !== null) {
                throw new InvalidArgumentException("EVILRESP_{$name} requires {$requirement}");
            }
        }
    }

    /** Keep unsigned 64-bit offsets as strings, including values above PHP_INT_MAX. */
    private static function unsigned(string $name, string $value): string
    {
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException("EVILRESP_{$name} requires unsigned decimal integers");
        }
        $value = ltrim($value, '0');
        $value = $value === '' ? '0' : $value;
        if (self::compareUnsigned($value, '18446744073709551615') > 0) {
            throw new InvalidArgumentException("EVILRESP_{$name} integer exceeds the proxy's 64-bit range");
        }

        return $value;
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }

    private static function target(string $value): string
    {
        if (strtoupper($value) === 'ANY') {
            return 'ANY';
        }
        $parts = explode('.', strtolower($value));
        if ($parts[0] !== 'root') {
            throw new InvalidArgumentException('EVILRESP_FRAMING_TARGET requires ANY or a root frame path');
        }
        $afterIndex = false;
        foreach (array_slice($parts, 1, preserve_keys: true) as $index => $part) {
            if ($afterIndex && in_array($part, ['key', 'value'], true)) {
                $afterIndex = false;
            } else {
                $parts[$index] = self::unsigned('FRAMING_TARGET', $part);
                $afterIndex = true;
            }
        }

        return implode('.', $parts);
    }
}
