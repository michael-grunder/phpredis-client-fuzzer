<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Small filesystem helpers used while shuffling per-run work directories and
 * reproducer artifacts around. Deliberately forgiving: a missing source is not
 * an error, because a crashing run may not have produced every artifact.
 */
final class Fs
{
    public static function ensureDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new \RuntimeException("cannot create directory: {$path}");
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && file_exists($path))) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * Move a file or directory, falling back to copy+delete across filesystems.
     */
    public static function move(string $from, string $to): bool
    {
        if (!file_exists($from) && !is_link($from)) {
            return false;
        }
        if (@rename($from, $to)) {
            return true;
        }

        if (is_dir($from)) {
            self::ensureDir($to);
            $entries = scandir($from);
            if ($entries === false) {
                return false;
            }
            $ok = true;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $ok = self::move($from . '/' . $entry, $to . '/' . $entry) && $ok;
            }
            @rmdir($from);

            return $ok;
        }

        if (@copy($from, $to)) {
            @unlink($from);
            return true;
        }

        return false;
    }
}
