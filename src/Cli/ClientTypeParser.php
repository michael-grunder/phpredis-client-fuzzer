<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientType;

final class ClientTypeParser
{
    public const MAX_CLIENTS = 10_000;

    /** @return non-empty-list<ClientType> */
    public static function parse(string $value): array
    {
        $types = [];

        foreach (Options::split($value) as $specification) {
            [$name, $count] = self::specification($specification);
            $type = ClientType::tryFrom(strtolower($name));
            if ($type === null) {
                throw new \InvalidArgumentException("Unknown client type: {$name}");
            }

            if (count($types) + $count > self::MAX_CLIENTS) {
                throw new \InvalidArgumentException(
                    '--client may create at most ' . self::MAX_CLIENTS . ' clients',
                );
            }

            for ($index = 0; $index < $count; $index++) {
                $types[] = $type;
            }
        }

        if ($types === []) {
            throw new \InvalidArgumentException('--client cannot be empty');
        }

        return $types;
    }

    /** @return array{string, positive-int} */
    private static function specification(string $value): array
    {
        $parts = explode(':', $value);
        if (count($parts) > 2) {
            throw new \InvalidArgumentException("Invalid client specification: {$value}");
        }

        $name = trim($parts[0]);
        if ($name === '') {
            throw new \InvalidArgumentException("Invalid client specification: {$value}");
        }

        if (!isset($parts[1])) {
            return [$name, 1];
        }

        $count = trim($parts[1]);
        if ($count === '' || !ctype_digit($count)) {
            throw new \InvalidArgumentException(
                "Client count must be a positive integer: {$value}",
            );
        }

        $parsed = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($parsed)) {
            throw new \InvalidArgumentException(
                "Client count must be a positive integer: {$value}",
            );
        }

        return [$name, $parsed];
    }
}
