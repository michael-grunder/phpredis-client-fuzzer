<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Log\Log;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\ScriptArg;
use Mgrunder\PhpredisCommandFuzzer\HasWeight;
use Mgrunder\PhpredisCommandFuzzer\WarningCollector;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

abstract class Command implements HasWeight {
    public const READ         = (1 << 1);
    public const WRITE        = (1 << 2);
    public const DELETE       = (1 << 3);
    public const FLUSH        = (1 << 4);
    public const BLOCKING     = (1 << 5);
    public const CACHED       = (1 << 6);
    public const INVALIDATING = (1 << 7);
    public const EXPIRE       = (1 << 8);
    public const RAW          = (1 << 10);
    public const SELECT       = (1 << 12);
    public const ADMIN        = (1 << 13);
    public const SCAN         = (1 << 14);
    public const LOCAL        = (1 << 15);
    public const CRASH        = (1 << 16);

    /* The client (not the server) splits this command across cluster nodes by
     * key slot, so generated keys are allowed to span slots.  See
     * SlotPolicy and FuzzConfig::beginStep(). */
    public const CROSSSLOT    = (1 << 17);

    public const STRING = 'string';
    public const INT    = 'int';
    public const FLOAT  = 'float';
    public const LIST   = 'list';
    public const SET    = 'set';
    public const ZSET   = 'zset';
    public const HASH   = 'hash';
    public const GEO    = 'geo';
    public const HLL    = 'hll';
    public const STREAM = 'stream';

    /* meta-types */
    public const ANY    = 'any';
    public const NONE   = 'none';

    abstract public function type(): string;
    abstract public function flags(): int;

    private ?string $name = null;

    private float $weight = 1.0;

    /**
     * @var array<string, Command>
     */
    private static $objects = [];

    private static ?WarningCollector $warningCollector = null;

    public function weight(): float {
        return $this->weight;
    }

    public function setWeight(float $weight): void {
        if ($weight < 0.0) {
            throw new \InvalidArgumentException('Command weights cannot be negative');
        }
        $this->weight = $weight;
    }

    protected function randomType(): string {
        return match (rand(0, 9)) {
            0 => self::STRING,
            1 => self::INT,
            2 => self::FLOAT,
            3 => self::LIST,
            4 => self::SET,
            5 => self::ZSET,
            6 => self::HASH,
            7 => self::GEO,
            8 => self::HLL,
            9 => self::STREAM,
        };
    }

    protected function randomRedisType(): string {
        return match (rand(0, 5)) {
            0 => self::STRING,
            1 => self::LIST,
            2 => self::SET,
            3 => self::ZSET,
            4 => self::HASH,
            5 => self::STREAM,
        };
    }

    public static function stringToFlag(string $flag): int {
        return match ($flag) {
            'read'         => self::READ,
            'write'        => self::WRITE,
            'delete'       => self::DELETE,
            'flush'        => self::FLUSH,
            'blocking'     => self::BLOCKING,
            'cached'       => self::CACHED,
            'invalidating' => self::INVALIDATING,
            'expire'       => self::EXPIRE,
            'raw'          => self::RAW,
            'select'       => self::SELECT,
            'admin'        => self::ADMIN,
            'scan'         => self::SCAN,
            'local'        => self::LOCAL,
            'crash'        => self::CRASH,
            'crossslot'    => self::CROSSSLOT,
            default        => 0
        };
    }

    public static function isRelay(Redis|RedisCluster|Relay|Cluster $client): bool {
        return ($client instanceof Relay) ||
               ($client instanceof Cluster);
    }

    /** @return int[] */
    protected function randomRange(int $maxlen): array {
        if ($maxlen <= 0)
            return [0, -1];

        if (rand() % 2 == 0)
            return [0, -1];

        $start = rand(-2 * $maxlen, 2 * $maxlen);
        $end = rand(-2 * $maxlen, 2 * $maxlen);

        return [$start, $end];
    }

    public function name(): string {
        $this->name ??= strtolower((new \ReflectionClass($this))->getShortName());
        return $this->name;
    }


    /** @return string */
    public static function toTypeString(int $type): string {
        return match ($type) {
            Redis::REDIS_STRING => 'string',
            Redis::REDIS_LIST   => 'list',
            Redis::REDIS_SET    => 'set',
            Redis::REDIS_ZSET   => 'zset',
            Redis::REDIS_HASH   => 'hash',
            Redis::REDIS_STREAM => 'stream',
            default => throw new \Exception('Unknown type constant: ' . $type),
        };
    }

    /* Given a Redis key name, try to map that to what type we expect it to
     * actually be in redis.  We have more type designators than there are
     * actual data types (e.g. hll is actually a string but used with pf*
     * commands */
    public static function fuzzTypeToRedisType(string $type): int {
        if (($pos = strpos($type, ':')) !== false)
            $type = substr($type, 0, $pos);

        return match ($type) {
            self::STRING => Redis::REDIS_STRING,
            self::INT    => Redis::REDIS_STRING,
            self::FLOAT  => Redis::REDIS_STRING,
            self::LIST   => Redis::REDIS_LIST,
            self::SET    => Redis::REDIS_SET,
            self::ZSET   => Redis::REDIS_ZSET,
            self::HASH   => Redis::REDIS_HASH,
            self::GEO    => Redis::REDIS_STRING,
            self::HLL    => Redis::REDIS_STRING,
            self::STREAM => Redis::REDIS_STREAM,
            default => throw new \Exception('Unknown type: ' . $type),
        };
    }

    public static function object(string $cmd): Command {
        $name = strtolower($cmd);
        $class = __NAMESPACE__ . '\\Command\\' . $name;

        $object = self::$objects[$class] ?? null;
        if ($object)
            return $object;

        if (!class_exists($class, false)) {
            $file = __DIR__ . '/Command/' . $name . '.php';
            if (!is_file($file)) {
                throw new \InvalidArgumentException("Unknown command: {$cmd}");
            }
            require_once $file;
        }

        $object = new $class;
        if ( ! $object instanceof Command)
            throw new \UnexpectedValueException("{$class} is not a command");
        self::$objects[$class] = $object;

        return $object;
    }

    public static function invoke(Redis|RedisCluster|Relay|Cluster $client,
                                  string $cmd, mixed ...$args): mixed
    {
        return self::object($cmd)->exec($client, ...$args);
    }

    public function matchType(int|string $type): bool {
        if (is_int($type))
            $type = $this->toTypeString($type);

        return $this->type() === self::ANY || $this->type() === $type;
    }

    public static function warningCollector(): WarningCollector {
        if (self::$warningCollector === null) {
            self::$warningCollector = new WarningCollector();
        }

        return self::$warningCollector;
    }

    /**
     * @return array<string, int>
     */
    public static function capturedWarnings(): array {
        return self::warningCollector()->warnings();
    }

    /** @return array<string, array<string, int>> */
    public static function capturedWarningsByCommand(): array {
        return self::warningCollector()->warningsByContext();
    }

    public static function setCapturedWarningCommand(?string $command): void {
        self::warningCollector()->setContext($command);
    }

    public static function resetCapturedWarnings(): void {
        $collector = self::warningCollector();
        $collector->register();
        $collector->reset();
    }

    /** @return array<string, int> */
    public static function finishCapturedWarnings(): array {
        $collector = self::warningCollector();
        $warnings = $collector->warnings();
        $collector->restore();

        return $warnings;
    }

    private function isSilentRedisError(string $e): bool {
        $patterns = [
            'no such key',
            'source and destination objects',
            'could not decode requested zset member',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($e, $pattern) !== false)
                return true;
        }

        return false;
    }

    protected function logRedisError(Redis|RedisCluster|Relay|Cluster $client, mixed ...$args): void {
        $error = $client->getLastError();

        assert($error != NULL);

        if ($this->isSilentRedisError($error))
            return;

        Log::info("Redis error:" . $error,[
            'server' => $this->clientServerInfo($client),
            'cmd'  => $this->name(),
            'args' => $args,
        ]);

        $client->clearLastError();
    }

    /**
     * @return array{host: string, port: int}|array{seeds: array<mixed>}
     */
    private function clientServerInfo(Redis|RedisCluster|Relay|Cluster $client): array {
        $standalone = ($client instanceof Redis) || ($client instanceof Relay);

        try {
            if ($client instanceof Redis || $client instanceOf Relay) {
                $host = (string)$client->getHost();
                $port = (int)$client->getPort();
                return ['host' => $host, 'port' => $port];
            } else {
                /** @var RedisCluster|Cluster $client */
                return ['seeds' => $client->_masters()];
            }
        } catch (\Exception $ex) {
            if ($standalone) {
                return ['host' => 'unknown', 'port' => 0];
            } else {
                return ['seeds' => []];
            }
        }
    }

    final public function exec(Redis|RedisCluster|Relay|Cluster $client, mixed ...$args): mixed {
        Log::debug("Executing command: {$this->name()}", [
            'server' => $this->clientServerInfo($client),
            'args'   => $args
        ]);

        $result = $this->cmd($client, $this->name(), ...$args);

        if ($client->getLastError())
            $this->logRedisError($client, ...$args);

        return $result;
    }

    final public function execRaw(Redis|RedisCluster|Relay|Cluster $client,
                                  mixed ...$args): mixed
    {
        array_unshift($args, $this->name());

        return $this->cmd($client, 'rawCommand', ...$args);
    }

    final public function cmd(Redis|RedisCluster|Relay|Cluster $client,
                              string $cmd, mixed ...$args): mixed
    {
        try {
            ScriptLogger::log($client, $cmd, $args);

            foreach ($args as $i => $arg) {
                if ($arg instanceof ScriptArg)
                    $args[$i] = $arg->value();
            }

            $result = $client->{$cmd}(...$args);
            if ($client->getLastError())
                $this->logRedisError($client, ...$args);

            return $result;
        } catch (\Exception $ex) {
            Log::debug("Error executing command: {$this->name()}", [
                'server' => $this->clientServerInfo($client),
                'args' => $args,
                'error' => $ex->getMessage()
            ]);

            return false;
        }
    }

    protected function isCluster(Redis|RedisCluster|Relay|Cluster $client): bool {
        return $client instanceof RedisCluster || $client instanceof Cluster;
    }

    protected function consumeKey(KeySample $keys): ?string {
        return $keys->consumeKey($this->type());
    }

    /** @return string[]|null */
    protected function consumeKeys(KeySample $keys): ?array {
        return $keys->consumeKeys($this->type());
    }

    public function randFloatInRange(float $min, float $max): float {
        return $min + (mt_rand() / mt_getrandmax()) * abs($max - $min);
    }
}
