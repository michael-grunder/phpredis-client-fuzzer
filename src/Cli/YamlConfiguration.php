<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Harness\HarnessOptions;
use Symfony\Component\Yaml\Yaml;

/** Expand explicit CLI configuration before supervision or reproduction. */
final class YamlConfiguration
{
    private const RUNNERS = ['tester', 'coercive', 'sapi', 'compatibility', 'diff'];

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function expand(array $arguments, string $runner): array
    {
        [$own, $child] = self::split($arguments);
        $file = null;
        $cli = [];
        for ($i = 0; $i < count($own); $i++) {
            $arg = $own[$i];
            if ($arg !== '--config' && !str_starts_with($arg, '--config=')) {
                $cli[] = $arg;
                continue;
            }
            if ($file !== null) {
                throw new \InvalidArgumentException('Use one --config file per invocation');
            }
            $file = $arg === '--config' ? ($own[++$i] ?? '') : substr($arg, 9);
            if ($file === '' || str_starts_with($file, '--')) {
                throw new \InvalidArgumentException('--config requires a file path');
            }
        }

        if ($file !== null) {
            try {
                if (!is_file($file) || !is_readable($file)) {
                    throw new \InvalidArgumentException('Cannot read configuration file');
                }
                $data = Yaml::parseFile($file, Yaml::PARSE_OBJECT_FOR_MAP
                    | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_DATETIME);
                if (!$data instanceof \stdClass) {
                    throw new \InvalidArgumentException('Configuration must be a YAML mapping');
                }
                $arguments = self::campaign(self::mapping($data), $runner, $cli, $child);
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException(
                    "{$file}: {$error->getMessage()}", 0, $error,
                );
            }
        }

        // Resolve child files now: probes, reduction and saved argv need their values.
        [$own, $child] = self::split($arguments);
        if ($child !== null && $runner === 'harness' && $child !== []) {
            $index = preg_match('/^php\d*(?:\.\d+)?$/', basename($child[0])) === 1
                ? 1 : 0;
            $script = $child[$index] ?? '';
            $name = basename($script);
            $path = realpath($script);
            $bin = $GLOBALS['_composer_bin_dir'] ?? null;
            $bundled = $path !== false && (
                $path === dirname(__DIR__, 2) . '/bin/' . $name
                || is_string($bin) && $path === realpath($bin . '/' . $name)
            );
            if ($bundled && in_array($name, self::RUNNERS, true)) {
                $child = [...array_slice($child, 0, $index + 1),
                    ...self::expand(array_slice($child, $index + 1), $name)];
            }
        } elseif ($child !== null && $runner === 'sapi') {
            $child = self::expand($child, 'tester');
        }

        return $child === null ? $own : [...$own, '--', ...$child];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $cli
     * @param list<string>|null $child
     * @return list<string>
     */
    private static function campaign(
        array $data, string $runner, array $cli, ?array $child,
    ): array {
        if (array_key_exists('runner', $data) && !is_string($data['runner'])) {
            throw new \InvalidArgumentException('runner must be a string');
        }
        $selected = $data['runner'] ?? ($runner === 'harness' ? null : $runner);
        if ($selected !== null && !in_array($selected, self::RUNNERS, true)) {
            throw new \InvalidArgumentException('Unknown runner; expected '
                . implode(', ', self::RUNNERS));
        }
        if ($runner !== 'harness' && $selected !== $runner) {
            throw new \InvalidArgumentException("runner must be {$runner} for this binary");
        }
        $allowed = ['runner'];
        if ($runner === 'harness') {
            $allowed[] = 'harness';
        }
        if ($selected !== null) {
            $allowed[] = 'workload';
            if (in_array($selected, ['sapi', 'compatibility'], true)) {
                $allowed[] = $selected;
            }
        }
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unexpected configuration key: {$key}");
            }
            if ($key !== 'runner' && !$value instanceof \stdClass) {
                throw new \InvalidArgumentException("{$key} must be a YAML mapping");
            }
        }

        if ($runner === 'harness') {
            $args = [...HarnessOptions::configurationArguments(self::section($data, 'harness')), ...$cli];
            if ($selected === null) {
                return $child === null ? $args : [...$args, '--', ...$child];
            }
            if (isset($child[0]) && !str_starts_with($child[0], '--')) {
                throw new \InvalidArgumentException(
                    'With a configured runner, put only its option overrides after --',
                );
            }
            return [...$args, '--', dirname(__DIR__, 2) . '/bin/' . $selected,
                ...self::runnerArguments($data, $selected, $child ?? [])];
        }

        return self::runnerArguments($data, $runner,
            $child === null ? $cli : [...$cli, '--', ...$child]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $cli
     * @return list<string>
     */
    private static function runnerArguments(array $data, string $runner, array $cli): array
    {
        $workload = self::section($data, 'workload');
        $args = $runner === 'diff'
            ? DiffApplication::configurationArguments($workload)
            : Application::configurationArguments($workload);
        if ($runner === 'sapi') {
            [$server, $child] = self::split($cli);
            return [...SapiApplication::configurationArguments(self::section($data, 'sapi')),
                ...$server, '--', ...$args, ...($child ?? [])];
        }
        if ($runner === 'compatibility') {
            $args = [...CompatibilityApplication::configurationArguments(
                self::section($data, 'compatibility'),
            ), ...$args];
        }
        return [...$args, ...$cli];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $name): array
    {
        $value = $data[$name] ?? new \stdClass();
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException("{$name} must be a YAML mapping");
        }
        return self::mapping($value);
    }

    /** @return array<string, mixed> */
    private static function mapping(\stdClass $value): array
    {
        $result = [];
        foreach (get_object_vars($value) as $key => $entry) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Configuration keys must be strings');
            }
            $result[$key] = $entry;
        }
        return $result;
    }

    /**
     * @param list<string> $arguments
     * @return array{list<string>, list<string>|null}
     */
    private static function split(array $arguments): array
    {
        $separator = array_search('--', $arguments, true);
        return $separator === false ? [$arguments, null]
            : [array_slice($arguments, 0, $separator), array_slice($arguments, $separator + 1)];
    }

    /**
     * Use the owning parser's option catalog; never maintain a second schema.
     * @param array<string, mixed> $values
     * @param list<string> $valueOptions
     * @param list<string> $flagOptions
     * @param list<string> $repeatable
     * @return list<string>
     */
    public static function options(
        array $values, array $valueOptions, array $flagOptions, array $repeatable,
    ): array {
        $arguments = [];
        foreach ($values as $name => $value) {
            if (in_array($name, $flagOptions, true)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException("{$name} must be true or false");
                }
                if ($value) {
                    $arguments[] = '--' . $name;
                }
                continue;
            }
            if (!in_array($name, $valueOptions, true)) {
                throw new \InvalidArgumentException("Unknown option: {$name}");
            }
            if (is_array($value) && (!array_is_list($value)
                || !in_array($name, $repeatable, true))) {
                throw new \InvalidArgumentException("{$name} does not accept a list");
            }
            foreach (is_array($value) ? $value : [$value] as $entry) {
                if (!is_string($entry) && !is_int($entry) && !is_float($entry)
                    || is_float($entry) && !is_finite($entry)) {
                    throw new \InvalidArgumentException("{$name} requires a string or finite number");
                }
                $arguments[] = '--' . $name . '=' . $entry;
            }
        }
        return $arguments;
    }
}
