<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

/** Per-user pointers contain paths only; connection credentials stay in the run directory. */
final class ServerDiscovery
{
    public function __construct(private readonly string $temporaryDirectory)
    {
    }

    private function prefix(): string
    {
        return 'rct-sapi-' . posix_geteuid() . '-';
    }

    public function id(string $directory): string
    {
        return $this->prefix() . substr(hash('sha256', $directory), 0, 12);
    }

    public function latestPath(): string
    {
        return $this->temporaryDirectory . '/' . $this->prefix() . 'latest';
    }

    public function publish(string $directory): void
    {
        $path = $this->temporaryDirectory . '/' . $this->id($directory);
        clearstatcache();
        if (file_exists($path) || is_link($path)) {
            if ($this->read($path) !== $directory) {
                throw new \RuntimeException('SAPI server ID collision: ' . $path);
            }
        } else {
            $this->write($path, $directory);
        }
        $this->write($this->latestPath(), $directory);
    }

    public function resolve(?string $id = null): string
    {
        if ($id !== null && preg_match('/\A' . $this->prefix() . '[a-f0-9]{12}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('invalid SAPI server ID for this user');
        }
        return $this->read($id === null ? $this->latestPath() : $this->temporaryDirectory . '/' . $id);
    }

    private function read(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            throw new \RuntimeException("no SAPI server pointer at {$path}; start serve or pass --directory");
        }
        if (($stat['mode'] & 0o170000) !== 0o100000 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('unsafe SAPI server pointer: ' . $path);
        }
        $contents = @file_get_contents($path, length: 8192);
        if ($contents === false || !str_starts_with($contents, '/') || !str_ends_with($contents, "\n")
            || str_contains(substr($contents, 0, -1), "\n") || str_contains($contents, "\0")) {
            throw new \RuntimeException('invalid SAPI server pointer: ' . $path);
        }
        return substr($contents, 0, -1);
    }

    private function write(string $path, string $directory): void
    {
        if (str_contains($directory, "\n") || !str_starts_with($directory, '/')) {
            throw new \InvalidArgumentException('SAPI discovery requires an absolute path without newlines');
        }
        if (file_exists($path) || is_link($path)) {
            $this->read($path);
        }
        $temporary = tempnam($this->temporaryDirectory, '.rct-sapi-');
        if ($temporary === false) {
            throw new \RuntimeException('cannot create SAPI discovery pointer');
        }
        try {
            Server::write($temporary, $directory . "\n");
            // Rename replaces the pointer atomically and never follows a destination symlink.
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('cannot publish SAPI server pointer: ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
