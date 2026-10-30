<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

/**
 * @param resource|null $context
 * @param int<0, max>|null $length
 */
function file_get_contents(
    string $filename,
    bool $use_include_path = false,
    $context = null,
    int $offset = 0,
    ?int $length = null,
): string|false
{
    return $filename === '/proc/-1/stat' ? ''
        : \file_get_contents($filename, $use_include_path, $context, $offset, $length);
}
