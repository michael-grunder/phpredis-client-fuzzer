<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

use Mgrunder\RedisClientTester\Values\ValueCode;

final class InvocationTrace
{
    /** @var list<array{string, string, list<string>}> */
    public static array $calls = [];
    /** @var list<array{int, int}> */
    public static array $referenceResults = [];
    /** @var (\Closure(string, string): void)|null */
    public static ?\Closure $before = null;

    /** @param list<mixed> $arguments */
    public static function record(string $identity, string $method, array $arguments): void
    {
        if (self::$before !== null) {
            (self::$before)($identity, $method);
        }
        self::$calls[] = [$identity, $method, array_map(ValueCode::export(...), $arguments)];
    }
}
