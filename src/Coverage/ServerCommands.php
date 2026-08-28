<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Coverage;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * The command table a Redis server actually implements, as reported by COMMAND.
 *
 * Container subcommands ("config|get") are folded away because the catalog is
 * organised per top-level command.
 */
final readonly class ServerCommands
{
    /** @param list<string> $names Lowercase, unique, sorted top-level command names. */
    private function __construct(public array $names)
    {
    }

    /**
     * COMMAND is used rather than COMMAND LIST because it predates Redis 7 and
     * reports only top-level commands.
     */
    public static function fromClient(Redis|RedisCluster|Relay|Cluster $client): self
    {
        $reply = $client instanceof RedisCluster || $client instanceof Cluster
            ? $client->rawCommand('phpredis-command-fuzzer:command-table', 'command')
            : $client->rawCommand('command');
        if (!is_array($reply) || $reply === []) {
            throw new \RuntimeException('The server returned no COMMAND output');
        }

        return self::fromReply($reply);
    }

    /** Reads one command name per line; blank lines and "#" comments are skipped. */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read command list file: {$path}");
        }

        $names = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $names[] = $line;
            }
        }

        return self::fromNames($names);
    }

    /**
     * Accepts both COMMAND rows (name first) and a flat list of names, so a
     * COMMAND LIST reply also works.
     *
     * @param array<mixed> $reply
     */
    public static function fromReply(array $reply): self
    {
        $names = [];
        foreach ($reply as $row) {
            $name = is_array($row) ? ($row[0] ?? null) : $row;
            if (!is_string($name)) {
                throw new \UnexpectedValueException('Malformed COMMAND reply');
            }
            $names[] = $name;
        }

        return self::fromNames($names);
    }

    /** @param list<string> $names */
    public static function fromNames(array $names): self
    {
        $normalized = [];
        foreach ($names as $name) {
            $name = strtolower(trim($name));

            /* Fold "config|get" into "config"; the catalog has no separate
             * class per container subcommand. */
            if (str_contains($name, '|')) {
                $name = substr($name, 0, (int) strpos($name, '|'));
            }

            if ($name !== '') {
                $normalized[$name] = true;
            }
        }

        $names = array_keys($normalized);
        sort($names);

        return new self($names);
    }

    public function count(): int
    {
        return count($this->names);
    }

    public function has(string $name): bool
    {
        return in_array(strtolower($name), $this->names, true);
    }
}
