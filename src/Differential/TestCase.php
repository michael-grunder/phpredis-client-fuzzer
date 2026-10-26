<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Differential;

/**
 * One generated differential testcase. Generation produces it and execution
 * only reads it, so the same case can be replayed, serialized, minimized, and
 * printed as a standalone reproducer.
 *
 * @phpstan-import-type Operation from DiffRuntime
 * @phpstan-import-type ClientOptions from DiffRuntime
 * @phpstan-import-type RuntimeCase from DiffRuntime
 */
final readonly class TestCase
{
    public const FORMAT = 1;

    /**
     * @param list<list<string>> $setup Raw commands for the neutral connection.
     * @param list<Operation> $test Client operations.
     * @param ClientOptions $options
     * @param list<string> $order Execution order of the two clients.
     */
    public function __construct(
        public int $seed,
        public int $index,
        public string $prefix,
        public array $setup,
        public array $test,
        public array $options,
        public array $order,
    ) {
        if ($prefix === '') {
            throw new \InvalidArgumentException('a testcase needs a key prefix');
        }
        // Setup commands always put their key first. Checking it here means a
        // generator or minimizer bug can never make the neutral connection
        // write outside the case's namespace.
        foreach ($setup as $command) {
            if (count($command) < 2 || !str_starts_with($command[1], $prefix)) {
                throw new \InvalidArgumentException('setup command outside the prefix: ' . DiffRuntime::describeArgv($command));
            }
        }
        sort($order);
        if ($order !== [DiffRuntime::PHPREDIS, DiffRuntime::RELAY]) {
            throw new \InvalidArgumentException('the order must name phpredis and relay once each');
        }
    }

    /** @return RuntimeCase */
    public function runtime(): array
    {
        return [
            'prefix' => $this->prefix,
            'setup' => $this->setup,
            'test' => $this->test,
            'options' => $this->options,
            'order' => $this->order,
        ];
    }

    /** @param list<Operation> $test */
    public function withTest(array $test): self
    {
        return new self($this->seed, $this->index, $this->prefix, $this->setup, $test, $this->options, $this->order);
    }

    /** @param list<list<string>> $setup */
    public function withSetup(array $setup): self
    {
        return new self($this->seed, $this->index, $this->prefix, $setup, $this->test, $this->options, $this->order);
    }

    /** @param ClientOptions $options */
    public function withOptions(array $options): self
    {
        return new self($this->seed, $this->index, $this->prefix, $this->setup, $this->test, $options, $this->order);
    }

    /** @param list<string> $order */
    public function withOrder(array $order): self
    {
        return new self($this->seed, $this->index, $this->prefix, $this->setup, $this->test, $this->options, $order);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'seed' => $this->seed,
            'index' => $this->index,
            'prefix' => ValueCodec::encode($this->prefix),
            'options' => $this->options,
            'order' => $this->order,
            'setup' => array_map(ValueCodec::encode(...), $this->setup),
            'test' => array_map(
                static fn (array $op): array => [$op[0], ValueCodec::encode($op[1]), $op[2]],
                $this->test,
            ),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('a testcase must be a JSON object');
        }

        return self::fromArray($data);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('unsupported testcase format');
        }

        $prefix = ValueCodec::decode($data['prefix'] ?? null);
        $options = $data['options'] ?? null;
        $order = $data['order'] ?? null;
        $setupData = $data['setup'] ?? null;
        $testData = $data['test'] ?? null;
        if (!is_string($prefix) || !is_array($options) || !is_array($order) || !is_array($setupData) || !is_array($testData)) {
            throw new \InvalidArgumentException('a testcase needs prefix, options, order, setup, and test');
        }

        $setup = [];
        foreach ($setupData as $command) {
            $decoded = ValueCodec::decode($command);
            if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
                throw new \InvalidArgumentException('setup commands must be non-empty lists');
            }
            $setup[] = array_map(static fn (mixed $arg): string => is_string($arg)
                ? $arg
                : throw new \InvalidArgumentException('setup arguments must be strings'), $decoded);
        }

        $test = [];
        foreach ($testData as $op) {
            if (!is_array($op) || count($op) !== 3 || !is_string($op[0] ?? null) || !is_string($op[2] ?? null)) {
                throw new \InvalidArgumentException('test operations must be [method, args, mode]');
            }
            $args = ValueCodec::decode($op[1]);
            if (!is_array($args) || !array_is_list($args)) {
                throw new \InvalidArgumentException('test arguments must be a list');
            }
            $test[] = [$op[0], $args, $op[2]];
        }

        $serializer = $options['serializer'] ?? null;
        $compression = $options['compression'] ?? null;
        $cache = $options['relay_cache'] ?? null;
        if (!is_string($serializer) || !is_string($compression) || !is_bool($cache)) {
            throw new \InvalidArgumentException('invalid testcase options');
        }

        $seed = $data['seed'] ?? 0;
        $index = $data['index'] ?? 0;

        return new self(
            is_int($seed) ? $seed : 0,
            is_int($index) ? $index : 0,
            $prefix,
            $setup,
            $test,
            ['serializer' => $serializer, 'compression' => $compression, 'relay_cache' => $cache],
            array_values(array_map(strval(...), array_filter($order, is_string(...)))),
        );
    }
}
