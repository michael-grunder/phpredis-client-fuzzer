<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

/**
 * The long-option parser shared by the package binaries.
 *
 * Accepts `--name=value` and `--name value` for value options and bare
 * `--name` for flags. Unknown options are rejected rather than ignored so a
 * typo never silently changes a workload.
 */
final class Options
{
    /** @param array<string, string|list<string>|true> $values */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $valueOptions Options that require a value.
     * @param list<string> $flagOptions Options that must not carry a value.
     * @param list<string> $repeatable Value options that collect a list.
     */
    public static function parse(
        array $arguments,
        array $valueOptions,
        array $flagOptions,
        array $repeatable = [],
    ): self {
        /** @var array<string, string|true> $values */
        $values = [];

        /** @var array<string, list<string>> $lists */
        $lists = [];

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                throw new \InvalidArgumentException("Unexpected argument: {$argument}");
            }

            $argument = substr($argument, 2);
            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
            } else {
                $name = $argument;
                $next = $arguments[$index + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $value = $next;
                    $index++;
                } else {
                    $value = true;
                }
            }

            if (!in_array($name, $valueOptions, true) && !in_array($name, $flagOptions, true)) {
                throw new \InvalidArgumentException("Unknown option: --{$name}");
            }
            if (in_array($name, $flagOptions, true) && $value !== true) {
                throw new \InvalidArgumentException("--{$name} does not accept a value");
            }
            if (in_array($name, $valueOptions, true) && $value === true) {
                throw new \InvalidArgumentException("--{$name} requires a value");
            }

            if (is_string($value) && in_array($name, $repeatable, true)) {
                $lists[$name][] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        return new self(array_merge($values, $lists));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function string(string $name, string $default): string
    {
        $value = $this->values[$name] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("--{$name} requires a value");
        }

        return $value;
    }

    public function nullableString(string $name): ?string
    {
        return $this->has($name) ? $this->string($name, '') : null;
    }

    public function integer(string $name, int $default): int
    {
        return $this->optionalInteger($name) ?? $default;
    }

    public function optionalInteger(string $name): ?int
    {
        if (!$this->has($name)) {
            return null;
        }

        $value = $this->string($name, '');
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("--{$name} must be an integer");
        }

        return (int) $value;
    }

    public function number(string $name, float $default): float
    {
        return $this->optionalNumber($name) ?? $default;
    }

    public function optionalNumber(string $name): ?float
    {
        if (!$this->has($name)) {
            return null;
        }

        $value = $this->string($name, '');
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("--{$name} must be numeric");
        }

        return (float) $value;
    }

    /** @return list<string> */
    public function csv(string $name, string $default = ''): array
    {
        return self::split($this->string($name, $default));
    }

    /**
     * Every value given for a repeatable option, each split on commas.
     *
     * @return list<string>
     */
    public function repeated(string $name): array
    {
        $value = $this->values[$name] ?? [];
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException("--{$name} requires a value");
            }
            $items = [...$items, ...self::split($entry)];
        }

        return $items;
    }

    /** @return list<string> */
    public static function split(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
