<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Log\Log;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;

final class Application
{
    private const VALUE_OPTIONS = [
        'client', 'host', 'port', 'seeds', 'username', 'password', 'timeout',
        'read-timeout', 'serializer', 'compression', 'prefix', 'steps',
        'seconds', 'seed', 'commands', 'weight', 'keys', 'members', 'shards',
        'min-length', 'max-length', 'max-command-keys', 'max-prefix-length',
        'wrongtype-chance', 'script-log',
    ];

    private const FLAG_OPTIONS = [
        'help', 'verbose', 'raw', 'include-blocking', 'include-local',
        'include-admin', 'include-flush', 'include-crashing',
        'no-relay-compatibility',
    ];

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = $this->parse($arguments);
            if (isset($options['help'])) {
                $this->write(self::HELP);
                return 0;
            }

            if (isset($options['verbose'])) {
                Log::setLogger(function (string $level, string $message, array $context): void {
                    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_THROW_ON_ERROR);
                    $this->write("[{$level}] {$message}{$suffix}\n", true);
                });
            }

            $clientTypes = $this->clientTypes($this->string($options, 'client', 'redis'));
            $host = $this->string($options, 'host', '127.0.0.1');
            $port = $this->integer($options, 'port', 6379);
            $seeds = $this->csv($this->string($options, 'seeds', "{$host}:{$port}"));
            if ($seeds === []) {
                throw new \InvalidArgumentException('--seeds cannot be empty');
            }

            $username = $this->nullableString($options, 'username');
            $password = $this->nullableString($options, 'password');
            $auth = $username !== null && $password !== null
                ? [$username, $password]
                : $password;

            $factory = new ClientFactory();
            $clients = [];
            foreach ($clientTypes as $type) {
                $clients[] = $factory->create(new ClientConfiguration(
                    type: $type,
                    host: $host,
                    port: $port,
                    seeds: $seeds,
                    timeout: $this->number($options, 'timeout', 1.0),
                    readTimeout: $this->number($options, 'read-timeout', 1.0),
                    auth: $auth,
                    prefix: $this->string($options, 'prefix', ''),
                    serializer: $this->string($options, 'serializer', 'none'),
                    compression: $this->string($options, 'compression', 'none'),
                    relayCompatibility: !isset($options['no-relay-compatibility']),
                ));
            }

            /** @var non-empty-list<\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster> $clients */
            $result = (new Fuzzer())->run($clients, new RunConfiguration(
                maxSteps: $this->integer($options, 'steps', 100),
                maxSeconds: $this->number($options, 'seconds', 0.0),
                seed: $this->optionalInteger($options, 'seed'),
                keys: $this->integer($options, 'keys', 100),
                shards: $this->integer($options, 'shards', 16),
                members: $this->integer($options, 'members', 10),
                minLength: $this->integer($options, 'min-length', 4),
                maxLength: $this->integer($options, 'max-length', 32),
                maxKeysPerCommand: $this->integer($options, 'max-command-keys', 10),
                maxPrefixLength: $this->integer($options, 'max-prefix-length', 0),
                wrongTypeChance: $this->number($options, 'wrongtype-chance', 0.0),
                commands: $this->csv($this->string($options, 'commands', '')),
                weights: $this->weights($options['weight'] ?? []),
                raw: isset($options['raw']),
                includeBlocking: isset($options['include-blocking']),
                includeLocal: isset($options['include-local']),
                includeAdmin: isset($options['include-admin']),
                includeFlush: isset($options['include-flush']),
                includeCrashing: isset($options['include-crashing']),
                scriptLog: $this->nullableString($options, 'script-log'),
            ));

            $this->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            return 0;
        } catch (\Throwable $throwable) {
            $this->write('phpredis-fuzz: ' . $throwable->getMessage() . "\n", true);
            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string|list<string>|true>
     */
    private function parse(array $arguments): array
    {
        $options = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                throw new \InvalidArgumentException("Unexpected argument: {$argument}");
            }

            $argument = substr($argument, 2);
            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
            } else {
                $name = $argument;
                $next = $arguments[$index + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $value = $next;
                    $index++;
                } else {
                    $value = true;
                }
            }

            if (!in_array($name, self::VALUE_OPTIONS, true)
                && !in_array($name, self::FLAG_OPTIONS, true)) {
                throw new \InvalidArgumentException("Unknown option: --{$name}");
            }
            if (in_array($name, self::FLAG_OPTIONS, true) && $value !== true) {
                throw new \InvalidArgumentException("--{$name} does not accept a value");
            }
            if (in_array($name, self::VALUE_OPTIONS, true) && $value === true) {
                throw new \InvalidArgumentException("--{$name} requires a value");
            }

            if ($name === 'weight') {
                $current = $options[$name] ?? [];
                $current[] = $value;
                $options[$name] = $current;
            } else {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function string(array $options, string $name, string $default): string
    {
        $value = $options[$name] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("--{$name} requires a value");
        }
        return $value;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function nullableString(array $options, string $name): ?string
    {
        return array_key_exists($name, $options) ? $this->string($options, $name, '') : null;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function integer(array $options, string $name, int $default): int
    {
        return $this->optionalInteger($options, $name) ?? $default;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function optionalInteger(array $options, string $name): ?int
    {
        if (!array_key_exists($name, $options)) {
            return null;
        }
        $value = $this->string($options, $name, '');
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("--{$name} must be an integer");
        }
        return (int) $value;
    }

    /** @param array<string, string|list<string>|true> $options */
    private function number(array $options, string $name, float $default): float
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        $value = $this->string($options, $name, '');
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("--{$name} must be numeric");
        }
        return (float) $value;
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /** @return non-empty-list<ClientType> */
    private function clientTypes(string $value): array
    {
        $types = [];
        foreach ($this->csv($value) as $name) {
            $type = ClientType::tryFrom(strtolower($name));
            if ($type === null) {
                throw new \InvalidArgumentException("Unknown client type: {$name}");
            }
            $types[] = $type;
        }
        if ($types === []) {
            throw new \InvalidArgumentException('--client cannot be empty');
        }
        return $types;
    }

    /**
     * @param string|list<string>|true $values
     * @return array<string, float>
     */
    private function weights(string|array|true $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $weights = [];
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/^(@?[a-zA-Z_]+):([0-9]+(?:\.[0-9]+)?)$/', $value, $matches)) {
                throw new \InvalidArgumentException("Invalid weight: " . var_export($value, true));
            }
            $weights[strtolower($matches[1])] = (float) $matches[2];
        }
        return $weights;
    }

    private function write(string $message, bool $error = false): void
    {
        $stream = $error ? STDERR : STDOUT;
        fwrite($stream, $message);
    }

    private const HELP = <<<'HELP'
phpredis-fuzz - fuzz PhpRedis-compatible clients against a disposable Redis target

Usage:
  phpredis-fuzz [options]

Clients and connection:
  --client=TYPE[,TYPE]       redis, redis-cluster, relay, relay-cluster (default: redis)
  --host=HOST                Standalone host (default: 127.0.0.1)
  --port=PORT                Standalone port (default: 6379)
  --seeds=HOST:PORT,...      Cluster seeds (defaults to host and port)
  --username=USER            ACL username
  --password=PASS            Password
  --timeout=SECONDS          Connect timeout (default: 1)
  --read-timeout=SECONDS     Read timeout (default: 1)
  --serializer=NAME          none, php, igbinary, msgpack, json
  --compression=NAME         none, lzf, zstd, lz4
  --prefix=PREFIX            Client key prefix
  --no-relay-compatibility   Disable Relay PhpRedis compatibility

Run configuration:
  --steps=N                  Maximum executed operations (default: 100)
  --seconds=N                Maximum duration; 0 disables (default: 0)
  --seed=N                   Reproducible random seed (generated when omitted)
  --commands=PATTERN,...     Include/exclude names, globs, or @flags
  --weight=TARGET:N          Set command or @flag weight; repeatable
  --keys=N                   Generated key space (default: 100)
  --members=N                Maximum generated members (default: 10)
  --shards=N                 Generated cluster hash tags (default: 16)
  --min-length=N             Minimum generated string length (default: 4)
  --max-length=N             Maximum generated string length (default: 32)
  --max-command-keys=N       Maximum keys in one command (default: 10)
  --max-prefix-length=N      Maximum random prefix length (default: 0)
  --wrongtype-chance=N       Probability from 0 to 1 (default: 0)
  --script-log=FILE          Write an executable PHP reproduction script
  --raw                      Enable raw-protocol command paths
  --include-blocking         Enable blocking commands
  --include-local            Enable commands that alter local client state
  --include-admin            Enable Redis administrative commands
  --include-flush            Enable FLUSHDB/FLUSHALL
  --include-crashing         Enable deliberately process-crashing commands
  --verbose                  Log command execution to stderr
  --help                     Show this help without connecting to Redis

The target is mutated. Use only an explicitly selected disposable Redis instance.
HELP;
}
