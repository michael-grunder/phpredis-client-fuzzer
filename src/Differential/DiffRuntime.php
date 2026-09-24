<?php

// Deliberately no strict_types: client methods are invoked from this file, and
// the differential fuzzer compares the clients under PHP's ordinary coercive
// calling convention, the one most application code uses. The source of this
// class is embedded verbatim in every generated reproduce.php, so it must not
// depend on anything outside itself.

namespace Mgrunder\PhpredisCommandFuzzer\Differential;

/**
 * The execution core shared by the differential fuzzer and its standalone
 * reproducers: a deliberately boring raw RESP connection used to reset, seed
 * and inspect Redis state, client construction, operation invocation, reply
 * canonicalization, and run comparison.
 *
 * A testcase is plain data:
 *
 *   prefix   every key the case touches starts with it
 *   setup    raw Redis commands (argv lists) sent over the neutral connection
 *   test     client operations: [method, args, comparison mode]
 *   options  client options applied identically to both clients
 *   order    which client runs first
 *
 * Comparison modes say what Redis semantics permit for a reply: `exact`,
 * `set` (unordered elements), `map` (unordered keys), `shape` (type and count
 * only, for random replies), `ttl`/`pttl` (timing tolerance), and
 * `exec:<mode>,<mode>,...` for transaction and pipeline results.
 *
 * @phpstan-type Operation array{0: string, 1: list<mixed>, 2: string}
 * @phpstan-type ClientOptions array{serializer: string, compression: string, relay_cache: bool}
 * @phpstan-type RuntimeCase array{
 *     prefix: string,
 *     setup: list<list<string>>,
 *     test: list<Operation>,
 *     options: ClientOptions,
 *     order: list<string>
 * }
 * @phpstan-type Server array{
 *     host: string,
 *     port: int,
 *     timeout: float,
 *     read_timeout: float,
 *     auth: string|list<string>|null,
 *     ttl_tolerance_ms: int,
 *     strict_messages: bool
 * }
 * @phpstan-type Outcome array{value: mixed, exception: string|null, warnings: list<string>, error: string|null}
 * @phpstan-type StateEntry array{0: string, 1: string, 2: mixed, 3: int}
 * @phpstan-type Snapshot list<StateEntry>
 * @phpstan-type Execution array{
 *     client: string,
 *     initial: Snapshot,
 *     outcomes: list<Outcome>,
 *     states: list<Snapshot>|null,
 *     final: Snapshot
 * }
 * @phpstan-type Comparison array{
 *     initial: string|null,
 *     reply: array{index: int, detail: string}|null,
 *     state: array{index: int|null, detail: string}|null,
 *     diverged: bool
 * }
 */
final class DiffRuntime
{
    public const PHPREDIS = 'phpredis';
    public const RELAY = 'relay';

    private const ERROR = "\0resp-error";

    // ------------------------------------------------------------------
    // Neutral raw RESP connection
    // ------------------------------------------------------------------

    /**
     * @param Server $server
     * @return resource
     */
    public static function connect(array $server)
    {
        $address = 'tcp://' . $server['host'] . ':' . $server['port'];
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($address, $errno, $errstr, max(0.001, $server['timeout']));
        if ($socket === false) {
            throw new \RuntimeException("cannot connect to {$address}: {$errstr}");
        }

        $readTimeout = $server['read_timeout'] > 0 ? $server['read_timeout'] : 30.0;
        stream_set_timeout($socket, (int) $readTimeout, (int) (fmod($readTimeout, 1.0) * 1_000_000));

        $auth = $server['auth'];
        if ($auth !== null) {
            self::command($socket, ['AUTH', ...(is_array($auth) ? $auth : [$auth])]);
        }

        return $socket;
    }

    /**
     * Send one command and return its reply; a Redis error reply throws.
     *
     * @param resource $socket
     * @param list<string> $argv
     */
    public static function command($socket, array $argv): mixed
    {
        return self::pipeline($socket, [$argv])[0];
    }

    /**
     * Send several commands in one write and read every reply, so an error
     * part way through never leaves unread replies on the connection.
     *
     * @param resource $socket
     * @param list<list<string>> $commands
     * @return list<mixed>
     */
    public static function pipeline($socket, array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $payload = '';
        foreach ($commands as $argv) {
            $payload .= '*' . count($argv) . "\r\n";
            foreach ($argv as $argument) {
                $payload .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
            }
        }
        self::write($socket, $payload);

        $replies = [];
        $failure = null;
        foreach ($commands as $index => $argv) {
            $reply = self::read($socket);
            if (is_array($reply) && isset($reply[self::ERROR])) {
                $failure ??= sprintf('%s (command #%d: %s)', self::scalar($reply[self::ERROR]), $index, self::describeArgv($argv));
                $reply = null;
            }
            $replies[] = $reply;
        }
        if ($failure !== null) {
            throw new \RuntimeException('Redis error: ' . $failure);
        }

        return $replies;
    }

    /** @param resource $socket */
    private static function write($socket, string $payload): void
    {
        while ($payload !== '') {
            $written = @fwrite($socket, $payload);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('neutral connection write failed');
            }
            $payload = substr($payload, $written);
        }
    }

    /** @param resource $socket */
    private static function read($socket): mixed
    {
        $line = self::line($socket);
        $type = $line[0] ?? '';
        $body = substr($line, 1);

        switch ($type) {
            case '+':
                return $body;
            case '-':
                return [self::ERROR => $body];
            case ':':
                return (int) $body;
            case '$':
                $length = (int) $body;
                if ($length < 0) {
                    return null;
                }
                $data = self::exact($socket, $length + 2);
                return substr($data, 0, $length);
            case '*':
                $count = (int) $body;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = self::read($socket);
                }
                return $items;
        }

        throw new \RuntimeException('unexpected RESP reply: ' . var_export($line, true));
    }

    /** @param resource $socket */
    private static function line($socket): string
    {
        $line = fgets($socket);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new \RuntimeException($meta['timed_out'] ? 'neutral connection read timed out' : 'neutral connection closed');
        }

        return rtrim($line, "\r\n");
    }

    /** @param resource $socket */
    private static function exact($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, max(1, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException($meta['timed_out'] ? 'neutral connection read timed out' : 'neutral connection closed');
            }
            $data .= $chunk;
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Namespace reset, seeding, and state snapshots
    // ------------------------------------------------------------------

    /**
     * Every key beneath $prefix, sorted. Only this prefix is ever scanned, so
     * concurrent workers with other prefixes are never observed or touched.
     *
     * @param resource $socket
     * @return list<string>
     */
    public static function keys($socket, string $prefix): array
    {
        $pattern = addcslashes($prefix, '\\*?[]') . '*';
        $cursor = '0';
        $keys = [];
        do {
            $reply = self::command($socket, ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000']);
            if (!is_array($reply) || count($reply) !== 2 || !is_array($reply[1])) {
                throw new \RuntimeException('unexpected SCAN reply');
            }
            $cursor = self::scalar($reply[0]);
            foreach ($reply[1] as $key) {
                $keys[self::scalar($key)] = true;
            }
        } while ($cursor !== '0');

        $keys = array_map('strval', array_keys($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Delete every key beneath $prefix and nothing else.
     *
     * @param resource $socket
     */
    public static function reset($socket, string $prefix): int
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('refusing to reset an empty prefix');
        }

        $deleted = 0;
        for ($pass = 0; $pass < 5; $pass++) {
            $keys = self::keys($socket, $prefix);
            if ($keys === []) {
                return $deleted;
            }
            foreach (array_chunk($keys, 500) as $chunk) {
                $deleted += self::integer(self::command($socket, ['UNLINK', ...$chunk]));
            }
        }

        throw new \RuntimeException("keys beneath {$prefix} keep reappearing after reset");
    }

    /**
     * @param resource $socket
     * @param list<list<string>> $setup
     */
    public static function seed($socket, array $setup): void
    {
        foreach (array_chunk($setup, 256) as $chunk) {
            self::pipeline($socket, $chunk);
        }
    }

    /**
     * Semantic state of every key beneath $prefix: key, type, contents in a
     * canonical order where Redis leaves ordering unspecified, and PTTL.
     *
     * @param resource $socket
     * @return Snapshot
     */
    public static function snapshot($socket, string $prefix): array
    {
        $keys = self::keys($socket, $prefix);
        if ($keys === []) {
            return [];
        }

        $meta = [];
        foreach ($keys as $key) {
            $meta[] = ['TYPE', $key];
            $meta[] = ['PTTL', $key];
        }
        $metaReplies = self::pipeline($socket, $meta);

        $reads = [];
        $types = [];
        foreach ($keys as $index => $key) {
            $type = self::scalar($metaReplies[$index * 2]);
            $types[] = $type;
            $reads[] = match ($type) {
                'string' => ['GET', $key],
                'list' => ['LRANGE', $key, '0', '-1'],
                'set' => ['SMEMBERS', $key],
                'hash' => ['HGETALL', $key],
                'zset' => ['ZRANGE', $key, '0', '-1', 'WITHSCORES'],
                'stream' => ['XRANGE', $key, '-', '+'],
                'none' => ['EXISTS', $key],
                default => ['DUMP', $key],
            };
        }
        $values = self::pipeline($socket, $reads);

        $snapshot = [];
        foreach ($keys as $index => $key) {
            $type = $types[$index];
            if ($type === 'none') {
                continue; // expired between SCAN and TYPE
            }
            $value = $values[$index];
            if ($type === 'set' && is_array($value)) {
                $value = array_map(self::scalar(...), $value);
                sort($value, SORT_STRING);
            } elseif ($type === 'hash' && is_array($value)) {
                $pairs = [];
                for ($i = 0; $i + 1 < count($value); $i += 2) {
                    $pairs[] = [self::scalar($value[$i]), self::scalar($value[$i + 1])];
                }
                usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
                $value = $pairs;
            }
            $snapshot[] = [$key, $type, $value, self::integer($metaReplies[$index * 2 + 1])];
        }

        return $snapshot;
    }

    /**
     * First difference between two snapshots, or null. A positive TTL on both
     * sides matches within the tolerance, because wall-clock time passes
     * between the two executions.
     *
     * @param Snapshot $a
     * @param Snapshot $b
     */
    public static function stateDiff(array $a, array $b, int $toleranceMs): ?string
    {
        $left = [];
        foreach ($a as $entry) {
            $left[$entry[0]] = $entry;
        }
        $right = [];
        foreach ($b as $entry) {
            $right[$entry[0]] = $entry;
        }

        $all = array_map('strval', array_keys($left + $right));
        sort($all, SORT_STRING);
        foreach ($all as $key) {
            $x = $left[$key] ?? null;
            $y = $right[$key] ?? null;
            if ($x === null || $y === null) {
                return sprintf('key %s exists only after %s', self::show($key), $x === null ? 'the second run' : 'the first run');
            }
            if ($x[1] !== $y[1]) {
                return sprintf('key %s has type %s vs %s', self::show($key), $x[1], $y[1]);
            }
            if ($x[2] !== $y[2]) {
                return sprintf("key %s (%s) contents differ:\n  first:  %s\n  second: %s", self::show($key), $x[1], self::show($x[2]), self::show($y[2]));
            }
            $ttlA = $x[3];
            $ttlB = $y[3];
            $sameClass = ($ttlA < 0 || $ttlB < 0) ? $ttlA === $ttlB : abs($ttlA - $ttlB) <= $toleranceMs;
            if (!$sameClass) {
                return sprintf('key %s PTTL %d vs %d', self::show($key), $ttlA, $ttlB);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Clients
    // ------------------------------------------------------------------

    /**
     * A freshly connected client of $kind with the case's options.
     *
     * @param Server $server
     * @param ClientOptions $options
     */
    public static function client(string $kind, array $server, array $options): object
    {
        if ($kind === self::RELAY) {
            if (!class_exists('Relay\\Relay')) {
                throw new \RuntimeException('the relay extension is not loaded');
            }
            $class = 'Relay\\Relay';
            $client = new \Relay\Relay();
            if (defined('Relay\\Relay::OPT_USE_CACHE')) {
                self::option($client, self::integer(constant('Relay\\Relay::OPT_USE_CACHE')), $options['relay_cache'], 'OPT_USE_CACHE');
            }
            if (defined('Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY')) {
                self::option($client, self::integer(constant('Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY')), true, 'OPT_PHPREDIS_COMPATIBILITY');
            }
        } elseif ($kind === self::PHPREDIS) {
            if (!class_exists('Redis')) {
                throw new \RuntimeException('the redis extension is not loaded');
            }
            $class = 'Redis';
            $client = new \Redis();
        } else {
            throw new \InvalidArgumentException("unknown client kind {$kind}");
        }

        if (!$client->connect($server['host'], $server['port'], $server['timeout'], null, 0, $server['read_timeout'])) {
            throw new \RuntimeException("{$kind} could not connect");
        }
        if ($server['auth'] !== null && !$client->auth($server['auth'])) {
            throw new \RuntimeException("{$kind} could not authenticate");
        }

        self::option($client, self::integer(constant($class . '::OPT_SERIALIZER')), self::named($class, 'SERIALIZER', $options['serializer']), 'OPT_SERIALIZER');
        if ($options['compression'] !== 'none') {
            self::option($client, self::integer(constant($class . '::OPT_COMPRESSION')), self::named($class, 'COMPRESSION', $options['compression']), 'OPT_COMPRESSION');
        }

        return $client;
    }

    private static function option(object $client, int $option, mixed $value, string $name): void
    {
        /** @var \Redis|\Relay\Relay $client */
        if (!$client->setOption($option, $value)) {
            throw new \RuntimeException(sprintf('%s rejected %s=%s', get_class($client), $name, var_export($value, true)));
        }
    }

    private static function named(string $class, string $group, string $name): int
    {
        $constant = $class . '::' . $group . '_' . strtoupper($name);
        if (!defined($constant)) {
            throw new \RuntimeException("{$constant} is not available");
        }

        return self::integer(constant($constant));
    }

    /**
     * Invoke one operation and record everything the caller could observe:
     * the canonical reply, a thrown exception, PHP warnings, and the client's
     * last Redis error.
     *
     * @param Operation $op
     * @return Outcome
     */
    public static function invoke(object $client, array $op): array
    {
        [$method, $args, $mode] = $op;

        /** @var \Redis|\Relay\Relay $client */
        try {
            $client->clearLastError();
        } catch (\Throwable) {
        }

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = self::normalizeMessage($message);
            return true;
        });

        $value = null;
        $exception = null;
        try {
            $value = $client->{$method}(...$args);
        } catch (\Throwable $throwable) {
            $exception = self::describeThrowable($throwable);
        } finally {
            restore_error_handler();
        }

        $error = null;
        try {
            $last = $client->getLastError();
            $error = is_string($last) ? $last : null;
        } catch (\Throwable) {
        }

        return [
            'value' => $exception === null ? self::canonical($value, $mode) : null,
            'exception' => $exception,
            'warnings' => $warnings,
            'error' => $error,
        ];
    }

    private static function throwableClass(?string $exception): ?string
    {
        return $exception === null ? null : explode(':', $exception, 2)[0];
    }

    private static function describeThrowable(\Throwable $throwable): string
    {
        $class = get_class($throwable);
        if ($class === 'RedisException' || $class === 'Relay\\Exception') {
            $class = 'ClientException';
        }

        return $class . ': ' . self::normalizeMessage($throwable->getMessage());
    }

    /**
     * Only the client class name is normalized: `Redis::get()` and
     * `Relay\Relay::get()` name the same API. Everything else in a message is
     * evidence and stays intact.
     */
    public static function normalizeMessage(string $message): string
    {
        return (string) preg_replace('/(?<![\\\\\w])(?:Relay\\\\Relay|Redis)::/', 'Client::', $message);
    }

    /**
     * A comparable form of a reply under a comparison mode. Floats become
     * tagged strings so NAN, INF and -0.0 compare meaningfully with ===, and
     * the client object returned while queueing becomes `<client>`.
     */
    public static function canonical(mixed $value, string $mode = 'exact'): mixed
    {
        if (str_starts_with($mode, 'exec:')) {
            $modes = explode(',', substr($mode, 5));
            if (is_array($value) && array_is_list($value) && count($value) === count($modes)) {
                $result = [];
                foreach ($value as $index => $item) {
                    $result[] = self::canonical($item, $modes[$index]);
                }
                return $result;
            }
            return self::canonical($value);
        }

        // Random replies (SRANDMEMBER, HRANDFIELD, ZRANDMEMBER): only how many
        // elements came back is deterministic, not which ones, nor their
        // PHP types once a serializer decodes them. Empty and error replies
        // (null, false) still compare exactly.
        if ($mode === 'shape') {
            if (is_array($value)) {
                return ['shape' => 'array', 'count' => count($value)];
            }
            if ($value !== null && $value !== false && !is_object($value)) {
                return ['shape' => 'value'];
            }
            return self::canonical($value);
        }

        if (is_float($value)) {
            return ['float' => self::floatText($value)];
        }
        if (is_object($value)) {
            return ($value instanceof \Redis || $value instanceof \Relay\Relay) ? '<client>' : '<object ' . get_class($value) . '>';
        }
        if (is_resource($value)) {
            return '<resource>';
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::canonical($item);
        }

        if ($mode === 'set') {
            $result = array_values($result);
            usort($result, static fn (mixed $a, mixed $b): int => strcmp(serialize($a), serialize($b)));
        } elseif ($mode === 'map') {
            ksort($result, SORT_STRING);
        }

        return $result;
    }

    public static function floatText(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }
        if ($value === 0.0 && fdiv(1.0, $value) < 0) {
            return '-0.0';
        }

        return var_export($value, true);
    }

    /**
     * By default exceptions compare by class and warnings by count: the two
     * clients word their own diagnostics differently, and treating wording as
     * a divergence would bury behavioral differences. Redis error text from
     * getLastError() comes from the server and always compares exactly.
     * $strictMessages compares all diagnostic text exactly.
     *
     * @param Outcome $a
     * @param Outcome $b
     */
    public static function outcomeDiff(array $a, array $b, string $mode, int $toleranceMs, bool $strictMessages = false): ?string
    {
        if ($strictMessages ? $a['exception'] !== $b['exception'] : self::throwableClass($a['exception']) !== self::throwableClass($b['exception'])) {
            return 'exception differs';
        }
        if ($strictMessages ? $a['warnings'] !== $b['warnings'] : count($a['warnings']) !== count($b['warnings'])) {
            return 'PHP warnings differ';
        }
        if ($a['error'] !== $b['error']) {
            return 'getLastError() differs';
        }

        $x = $a['value'];
        $y = $b['value'];
        if ($x === $y) {
            return null;
        }
        if (($mode === 'ttl' || $mode === 'pttl') && is_int($x) && is_int($y) && $x >= 0 && $y >= 0) {
            $tolerance = $mode === 'ttl' ? intdiv($toleranceMs + 999, 1000) + 1 : $toleranceMs;
            if (abs($x - $y) <= $tolerance) {
                return null;
            }
        }

        return 'reply differs';
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /**
     * reset -> seed -> client runs the test sequence, recording every
     * outcome and the prefix's state before and after (and optionally after
     * every operation).
     *
     * @param resource $socket
     * @param RuntimeCase $case
     * @param Server $server
     * @return Execution
     */
    public static function execute($socket, string $kind, array $case, array $server, bool $perOpState): array
    {
        self::reset($socket, $case['prefix']);
        self::seed($socket, $case['setup']);
        $initial = self::snapshot($socket, $case['prefix']);

        $client = self::client($kind, $server, $case['options']);
        $outcomes = [];
        $states = $perOpState ? [] : null;
        try {
            foreach ($case['test'] as $op) {
                $outcomes[] = self::invoke($client, $op);
                if ($states !== null) {
                    $states[] = self::snapshot($socket, $case['prefix']);
                }
            }
        } finally {
            try {
                /** @var \Redis|\Relay\Relay $client */
                $client->close();
            } catch (\Throwable) {
            }
        }

        return [
            'client' => $kind,
            'initial' => $initial,
            'outcomes' => $outcomes,
            'states' => $states,
            'final' => self::snapshot($socket, $case['prefix']),
        ];
    }

    /**
     * @param RuntimeCase $case
     * @param Execution $a
     * @param Execution $b
     * @return Comparison
     */
    public static function compare(array $case, array $a, array $b, int $toleranceMs, bool $strictMessages = false): array
    {
        $initial = self::stateDiff($a['initial'], $b['initial'], $toleranceMs);

        $reply = null;
        foreach ($case['test'] as $index => $op) {
            $outA = $a['outcomes'][$index] ?? null;
            $outB = $b['outcomes'][$index] ?? null;
            if ($outA === null || $outB === null) {
                if ($outA !== $outB) {
                    $reply = ['index' => $index, 'detail' => 'one run stopped early'];
                }
                break;
            }
            $detail = self::outcomeDiff($outA, $outB, $op[2], $toleranceMs, $strictMessages);
            if ($detail !== null) {
                $reply = ['index' => $index, 'detail' => $detail];
                break;
            }
        }

        $state = null;
        if ($a['states'] !== null && $b['states'] !== null) {
            foreach ($a['states'] as $index => $snapshot) {
                $other = $b['states'][$index] ?? null;
                if ($other === null) {
                    break;
                }
                $detail = self::stateDiff($snapshot, $other, $toleranceMs);
                if ($detail !== null) {
                    $state = ['index' => $index, 'detail' => $detail];
                    break;
                }
            }
        }
        if ($state === null) {
            $detail = self::stateDiff($a['final'], $b['final'], $toleranceMs);
            if ($detail !== null) {
                $state = ['index' => null, 'detail' => $detail];
            }
        }

        return [
            'initial' => $initial,
            'reply' => $reply,
            'state' => $state,
            'diverged' => $initial !== null || $reply !== null || $state !== null,
        ];
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /** A compact single-line PHP-like rendering that keeps binary data visible. */
    public static function show(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::floatText($value);
        }
        if (is_string($value)) {
            if (strlen($value) > 256) {
                return self::quote(substr($value, 0, 96)) . sprintf('...(%d bytes, md5 %s)', strlen($value), md5($value));
            }
            return self::quote($value);
        }
        if (is_array($value)) {
            if (count($value) === 1 && isset($value['float']) && is_string($value['float'])) {
                return $value['float'];
            }
            $parts = [];
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                $parts[] = $list ? self::show($item) : self::show($key) . ' => ' . self::show($item);
            }
            return '[' . implode(', ', $parts) . ']';
        }

        return get_debug_type($value);
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]|["\\\\$]/', $value) !== 1) {
            return '"' . $value . '"';
        }

        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $ord = ord($char);
            $out .= match (true) {
                $char === '"' => '\\"',
                $char === '\\' => '\\\\',
                $char === '$' => '\\$',
                $char === "\n" => '\\n',
                $char === "\r" => '\\r',
                $char === "\t" => '\\t',
                $ord < 0x20 || $ord > 0x7e => sprintf('\\x%02x', $ord),
                default => $char,
            };
        }

        return '"' . $out . '"';
    }

    /** @param Operation $op */
    public static function describeOp(array $op): string
    {
        return $op[0] . '(' . implode(', ', array_map(self::show(...), $op[1])) . ')';
    }

    /** @param list<string> $argv */
    public static function describeArgv(array $argv): string
    {
        return implode(' ', array_map(self::show(...), $argv));
    }

    /** @param Outcome $outcome */
    public static function describeOutcome(array $outcome): string
    {
        $lines = [];
        if ($outcome['exception'] !== null) {
            $lines[] = 'throws ' . $outcome['exception'];
        } else {
            $lines[] = self::show($outcome['value']);
        }
        foreach ($outcome['warnings'] as $warning) {
            $lines[] = 'warning: ' . $warning;
        }
        if ($outcome['error'] !== null) {
            $lines[] = 'getLastError(): ' . $outcome['error'];
        }

        return implode("\n    ", $lines);
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : 0;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    // ------------------------------------------------------------------
    // Standalone reproducer entry point
    // ------------------------------------------------------------------

    /**
     * Run a case against both clients in its recorded order and report every
     * operation. Returns the process exit status: 1 when the divergence still
     * reproduces, 0 when it does not.
     *
     * Environment overrides: REDIS_HOST, REDIS_PORT, and DIFF_ORDER
     * (`phpredis,relay` or `relay,phpredis`).
     *
     * @param RuntimeCase $case
     * @param Server $server
     */
    public static function main(array $case, array $server): int
    {
        $host = getenv('REDIS_HOST');
        if (is_string($host) && $host !== '') {
            $server['host'] = $host;
        }
        $port = getenv('REDIS_PORT');
        if (is_string($port) && ctype_digit($port)) {
            $server['port'] = (int) $port;
        }
        $order = getenv('DIFF_ORDER');
        if (is_string($order) && $order !== '') {
            $case['order'] = array_map('trim', explode(',', $order));
        }
        if (count($case['order']) !== 2) {
            fwrite(STDERR, "the order must name two clients\n");
            return 2;
        }

        [$first, $second] = $case['order'];
        $label = [self::PHPREDIS => 'PhpRedis', self::RELAY => 'Relay'];
        printf("Target %s:%d, prefix %s, order %s -> %s\n", $server['host'], $server['port'], self::show($case['prefix']), $first, $second);
        printf("Diagnostics compared %s\n", $server['strict_messages'] ? 'exactly' : 'by exception class and warning count');
        printf("Options: serializer=%s compression=%s relay_cache=%s\n", $case['options']['serializer'], $case['options']['compression'], $case['options']['relay_cache'] ? 'on' : 'off');
        printf("PHP %s, redis %s, relay %s\n\n", PHP_VERSION, phpversion('redis') ?: 'n/a', phpversion('relay') ?: 'n/a');

        $socket = self::connect($server);
        try {
            $a = self::execute($socket, $first, $case, $server, true);
            $b = self::execute($socket, $second, $case, $server, true);
            self::reset($socket, $case['prefix']);
        } catch (\Throwable $throwable) {
            fwrite(STDERR, 'execution failed: ' . $throwable->getMessage() . "\n");
            return 2;
        }

        $tolerance = $server['ttl_tolerance_ms'];
        $strict = $server['strict_messages'];
        $comparison = self::compare($case, $a, $b, $tolerance, $strict);
        $nameA = $label[$first] ?? $first;
        $nameB = $label[$second] ?? $second;

        if ($comparison['initial'] !== null) {
            echo "Initial state DIFFERS (the runs did not start from identical data):\n  {$comparison['initial']}\n\n";
        }

        $firstReported = false;
        foreach ($case['test'] as $index => $op) {
            $outA = $a['outcomes'][$index];
            $outB = $b['outcomes'][$index];
            printf("Command %d: %s\n", $index, self::describeOp($op));
            $detail = self::outcomeDiff($outA, $outB, $op[2], $tolerance, $strict);
            if ($detail === null) {
                echo self::outcomeDiff($outA, $outB, $op[2], $tolerance, true) === null ? "  MATCH\n" : "  MATCH (diagnostic wording differs)\n";
            } else {
                echo '  DIFFERENCE (' . $detail . ($firstReported ? '' : ', first') . ")\n";
                echo "  {$nameA}:\n    " . self::describeOutcome($outA) . "\n";
                echo "  {$nameB}:\n    " . self::describeOutcome($outB) . "\n";
                $firstReported = true;
            }
            $states = [$a['states'][$index] ?? [], $b['states'][$index] ?? []];
            $stateDetail = self::stateDiff($states[0], $states[1], $tolerance);
            if ($stateDetail !== null && $comparison['state'] !== null && $comparison['state']['index'] === $index) {
                echo "  STATE DIVERGES after this command (first state divergence):\n    "
                    . str_replace("\n", "\n    ", $stateDetail) . "\n";
            }
        }

        echo "\n";
        $final = self::stateDiff($a['final'], $b['final'], $tolerance);
        echo $final === null ? "Final state: MATCH\n" : "Final state: DIFFERENCE\n  {$nameA} vs {$nameB}: " . str_replace("\n", "\n  ", $final) . "\n";

        if (!$comparison['diverged']) {
            echo "\nRESULT: no divergence (the difference did not reproduce)\n";
            return 0;
        }

        echo "\nRESULT: DIVERGENCE";
        if ($comparison['reply'] !== null) {
            echo ' reply@' . $comparison['reply']['index'];
        }
        if ($comparison['state'] !== null) {
            echo ' state@' . ($comparison['state']['index'] ?? 'final');
        }
        if ($comparison['initial'] !== null) {
            echo ' initial-state';
        }
        echo "\n";

        return 1;
    }
}
