<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

final class ZSetRange
{
    /** @return array{string, string} */
    public static function scores(FuzzConfig $config): array
    {
        $scores = [$config->getRandomFloat(), $config->getRandomFloat()];
        sort($scores, SORT_NUMERIC);

        return [
            rand(0, 3) === 0 ? '-inf' : (rand(0, 1) ? '(' : '') . $scores[0],
            rand(0, 3) === 0 ? '+inf' : (rand(0, 1) ? '(' : '') . $scores[1],
        ];
    }

    /** @return array{string, string} */
    public static function lex(FuzzConfig $config): array
    {
        $members = [$config->getRandomMember(Command::ZSET), $config->getRandomMember(Command::ZSET)];
        sort($members, SORT_STRING);

        return [
            rand(0, 3) === 0 ? '-' : (rand(0, 1) ? '(' : '[') . $members[0],
            rand(0, 3) === 0 ? '+' : (rand(0, 1) ? '(' : '[') . $members[1],
        ];
    }
}
