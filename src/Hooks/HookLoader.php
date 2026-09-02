<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

final class HookLoader
{
    /** @param list<string> $paths */
    public static function load(array $paths): HookRegistry
    {
        $registry = new HookRegistry();
        foreach ($paths as $path) {
            self::loadFile($registry, $path);
        }

        return $registry;
    }

    private static function loadFile(HookRegistry $registry, string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \InvalidArgumentException("Hook file is not readable: {$path}");
        }

        $loaded = (static function (string $file): mixed {
            return require $file;
        })($resolved);
        if ($loaded instanceof InvocationHook) {
            $registry->add(pathinfo($resolved, PATHINFO_FILENAME), $loaded);
        } elseif (is_callable($loaded)) {
            $loaded($registry);
        } else {
            throw new \UnexpectedValueException(
                "Hook file must return a callable or InvocationHook: {$resolved}",
            );
        }
        $sha256 = hash_file('sha256', $resolved);
        if ($sha256 === false) {
            throw new \RuntimeException("Unable to hash hook file: {$resolved}");
        }
        $registry->addSource($resolved, $sha256);
    }
}
