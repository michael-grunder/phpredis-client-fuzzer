<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class HookLoader
{
    /**
     * @param list<string> $values CLI values in HOOK.KEY=VALUE form.
     * @return array<string, array<string, string>>
     */
    public static function parseArguments(array $values): array
    {
        $arguments = [];
        foreach ($values as $value) {
            if (preg_match('/\A([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)=(.*)\z/s', $value, $matches) !== 1) {
                throw new \InvalidArgumentException('--hook-arg must use HOOK.KEY=VALUE');
            }
            [, $hook, $key, $argument] = $matches;
            if (isset($arguments[$hook][$key])) {
                throw new \InvalidArgumentException("Duplicate --hook-arg: {$hook}.{$key}");
            }
            $arguments[$hook][$key] = $argument;
        }

        return $arguments;
    }

    /**
     * @param list<string> $paths
     * @param array<string, array<string, string>> $arguments Options by hook filename without extension.
     */
    public static function load(array $paths, array $arguments = []): HookRegistry
    {
        $resolvedPaths = [];
        foreach ($paths as $path) {
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
                throw new \InvalidArgumentException("Hook file is not readable: {$path}");
            }
            $resolvedPaths[] = $resolved;
        }
        $names = array_count_values(array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $resolvedPaths,
        ));
        foreach ($arguments as $name => $options) {
            if (($names[$name] ?? 0) !== 1) {
                throw new \InvalidArgumentException("Hook arguments require exactly one --hook named {$name}");
            }
        }
        $registry = new HookRegistry();
        foreach ($resolvedPaths as $path) {
            self::loadFile($registry, $path, $arguments[pathinfo($path, PATHINFO_FILENAME)] ?? []);
        }

        return $registry;
    }

    /** @param array<string, string> $arguments */
    private static function loadFile(HookRegistry $registry, string $resolved, array $arguments): void
    {
        $loaded = (static function (string $file, array $arguments): mixed {
            return require $file;
        })($resolved, $arguments);
        $id = pathinfo($resolved, PATHINFO_FILENAME);
        /* One object may be both an invocation hook and a lifecycle hook, so
           every surface it implements is registered under the same id. */
        $registered = false;
        if ($loaded instanceof InvocationHook) {
            $registry->add($id, $loaded);
            $registered = true;
        }
        if (is_object($loaded) && HookRegistry::isLifecycleHook($loaded)) {
            $registry->addLifecycleHook($id, $loaded);
            $registered = true;
        }
        if (!$registered) {
            if (!is_callable($loaded)) {
                throw new \UnexpectedValueException(
                    "Hook file must return a callable, an InvocationHook, or a lifecycle hook: {$resolved}",
                );
            }
            $loaded($registry, $arguments);
        }
        $sha256 = hash_file('sha256', $resolved);
        if ($sha256 === false) {
            throw new \RuntimeException("Unable to hash hook file: {$resolved}");
        }
        $registry->addSource($resolved, $sha256, $arguments);
    }
}
