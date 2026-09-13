<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\Values\CallbackContext;
use Mgrunder\PhpredisCommandFuzzer\Values\MutatingValue;
use Mgrunder\PhpredisCommandFuzzer\Values\SerializedValue;
use Mgrunder\PhpredisCommandFuzzer\Values\StringValue;
use Mgrunder\PhpredisCommandFuzzer\Values\ValueCode;
use Mgrunder\PhpredisCommandFuzzer\Values\ValueCorpus;
use Mgrunder\PhpredisCommandFuzzer\Values\WakeupValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

final class ValueFixtureInvoker implements ClientInvoker
{
    /** @var list<string> */
    public array $methods = [];

    public function __construct(private string $encoded)
    {
    }

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        $this->methods[] = $method;
        if ($method === 'get') {
            return unserialize($this->encoded);
        }

        return true;
    }
}

final class ValueCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        mt_srand(42);
    }

    protected function tearDown(): void
    {
        ScriptLogger::finish();
        Command::finishCapturedWarnings();
    }

    public function testStringBoundariesAreReachableAndRespectTheCap(): void
    {
        $corpus = new ValueCorpus();
        $lengths = [];
        for ($i = 0; $i < 300; $i++) {
            $value = $corpus->string('repeated');
            $lengths[strlen($value)] = true;
        }
        foreach ([0, 1, 31, 32, 33, 255, 256, 257, 511, 512, 513, 4095, 4096, 4097] as $length) {
            self::assertArrayHasKey($length, $lengths);
        }
        self::assertSame("\0", $corpus->string('nul'));
        self::assertSame("\r\n\0\r\n", $corpus->string('crlf'));
        self::assertSame(false, preg_match('//u', $corpus->string('invalid-utf8')));
        foreach ([1, 32, 256] as $cap) {
            foreach (ValueCorpus::STRING_VARIANTS as $variant) {
                self::assertLessThanOrEqual($cap, strlen((new ValueCorpus($cap))->string($variant)));
            }
        }
        mt_srand(42);
        $first = $corpus->string('binary');
        mt_srand(42);
        self::assertSame($first, $corpus->string('binary'));
    }

    public function testNumbersDoNotExpandAllocationSensitiveIntegerGeneration(): void
    {
        $corpus = new ValueCorpus();
        $seen = [];
        $config = new FuzzConfig();
        for ($i = 0; $i < 500; $i++) {
            $seen[serialize($corpus->value(true, variant: 'integer'))] = true;
            $seen[serialize($corpus->value(true, variant: 'float'))] = true;
            self::assertGreaterThanOrEqual(-32768, $config->getRandomInt());
            self::assertLessThanOrEqual(32767, $config->getRandomInt());
        }
        foreach ([PHP_INT_MIN, PHP_INT_MAX, 2147483648, 0.0, -0.0, INF, -INF, NAN] as $number) {
            self::assertArrayHasKey(serialize($number), $seen);
        }
    }

    public function testReproductionPreservesReferencesAndObjectIdentityWithoutCallbacks(): void
    {
        $value = (new ValueCorpus())->value(true, variant: 'references');
        $replayed = eval('return ' . ValueCode::export($value) . ';');
        if (!is_array($replayed) || !isset($replayed[2]) || !is_array($replayed[2])) {
            self::fail('Expected reference graph');
        }
        $replayed[0] = 'mutated';
        self::assertSame('mutated', $replayed[1]);
        self::assertSame('mutated', $replayed[2]['alias']);

        $object = new SerializedValue("binary\0payload", 'throw-serialize');
        $replayed = eval('return ' . ValueCode::export([$object, $object]) . ';');
        if (!is_array($replayed) || !$replayed[0] instanceof SerializedValue) {
            self::fail('Expected fixture graph');
        }
        self::assertSame($replayed[0], $replayed[1]);
        self::assertSame("binary\0payload", $replayed[0]->payload);
        self::assertSame('throw-serialize', $replayed[0]->behavior);
    }

    public function testOrdinaryCallbacksExecuteAndDoNotNeedMutationPermission(): void
    {
        self::assertSame('payload', (string) new StringValue('payload'));
        $value = unserialize(serialize(new SerializedValue('payload')));
        self::assertInstanceOf(SerializedValue::class, $value);
        $value = unserialize(serialize(new WakeupValue('payload')));
        self::assertInstanceOf(WakeupValue::class, $value);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deserialization callback');
        unserialize(serialize(new SerializedValue('payload', 'throw-unserialize')));
    }

    public function testMutatingValuesRequireOptInAtGenerationTime(): void
    {
        $corpus = new ValueCorpus(32);
        for ($i = 0; $i < 1000; $i++) {
            self::assertNotInstanceOf(MutatingValue::class, $corpus->value(true));
        }
        self::assertInstanceOf(MutatingValue::class, $corpus->value(true, true, 'mutating'));
        $this->expectException(\InvalidArgumentException::class);
        $corpus->value(true, false, 'mutating');
    }

    /** @return iterable<string, array{class-string<Redis|RedisCluster|Relay|Cluster>}> */
    public static function clients(): iterable
    {
        foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string<Redis|RedisCluster|Relay|Cluster> $class */
    #[DataProvider('clients')]
    public function testPersistedMutatingObjectsAreInertUntilInvocationOptIn(string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional extension unavailable');
        }
        $client = $this->createStub($class);
        foreach (['close', 'serializer', 'delete'] as $action) {
            $invoker = new ValueFixtureInvoker(serialize(new MutatingValue('payload', $action)));
            $command = new Command\get();
            $command->setClientInvoker($invoker);
            $command->exec($client, 'key');
            self::assertSame(['get'], $invoker->methods);
            $command->setAllowDeserializationMutations(true);
            $command->exec($client, 'key');
            self::assertSame(['get', 'get', CallbackContext::operation($action)[0]], $invoker->methods);
            $command->setAllowDeserializationMutations(false);
            $command->exec($client, 'key');
            self::assertCount(4, $invoker->methods);
        }
    }

    public function testMutationIsBoundedAndPermissionRestoredAfterException(): void
    {
        $count = 0;
        $mutation = static function (string $action) use (&$count): void {
            $count++;
            CallbackContext::mutate($action);
        };
        CallbackContext::invoke(static function (): void {
            CallbackContext::mutate('close');
            CallbackContext::mutate('close');
        }, $mutation);
        self::assertSame(1, $count);
        try {
            CallbackContext::invoke(static function (): void {
                CallbackContext::mutate('close');
                throw new \RuntimeException('outer');
            }, $mutation);
        } catch (\RuntimeException $error) {
            self::assertSame('outer', $error->getMessage());
        }
        CallbackContext::mutate('close');
        self::assertSame(2, $count);
        self::assertFalse(CallbackContext::isMutating());
    }

    public function testScriptEmbedsFixturesAndDoesNotDuplicateTheMutation(): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getHost')->willReturn('server-free');
        $client->method('getPort')->willReturn(6379);
        $path = tempnam(sys_get_temp_dir(), 'value-reproduction-');
        if ($path === false) {
            self::fail('Cannot create script');
        }
        try {
            ScriptLogger::init($path, ['deserialization-mutations' => true], [$client]);
            $command = new Command\get();
            $command->setAllowDeserializationMutations(true);
            $command->setClientInvoker(new ValueFixtureInvoker(serialize(new MutatingValue('payload', 'close'))));
            $command->exec($client, 'key');
            ScriptLogger::finish();
            $script = file_get_contents($path);
            if ($script === false) {
                self::fail('Cannot read script');
            }
            self::assertNotEmpty(token_get_all($script, TOKEN_PARSE));
            self::assertStringContainsString('class MutatingValue', $script);
            self::assertStringContainsString('CallbackContext::replay($', $script);
            self::assertStringContainsString('Deserialization callback invoked close', $script);
            self::assertStringNotContainsString('->close(', $script);
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }

    public function testLocalPhpSerializerRoundTripsOrdinaryFixturesAndAliases(): void
    {
        $client = new Redis();
        $client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        foreach (['references', 'sparse', 'nested', 'serialized', 'wakeup'] as $variant) {
            $value = (new ValueCorpus())->value(true, variant: $variant);
            $encoded = (new \Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker())->invoke($client, '_serialize', [$value]);
            if (!is_string($encoded)) {
                self::fail('Expected PHP serialized string');
            }
            $decoded = $client->_unserialize($encoded);
            self::assertSame(ValueCode::export($value), ValueCode::export($decoded));
        }
    }

    public function testDecodersAttemptRecoveryAfterThrownMalformedInput(): void
    {
        $client = new Redis();
        foreach ([new Command\_unserialize(), new Command\_unpack(), new Command\_uncompress()] as $command) {
            if (!method_exists($client, $command->name())) {
                continue;
            }
            $invoker = new class implements ClientInvoker {
                /** @var list<array{string, list<mixed>}> */
                public array $calls = [];
                public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
                {
                    $this->calls[] = [$method, $arguments];
                    if (count($this->calls) === 1) {
                        return 'encoded-valid-payload';
                    }
                    if (count($this->calls) === 2) {
                        throw new \RuntimeException('malformed decode');
                    }
                    return 'recovered';
                }
            };
            $command->setClientInvoker($invoker);
            self::assertSame(Command::LOCAL, $command->flags());
            try {
                $command->fuzz($client, new FuzzConfig());
                self::fail('Malformed decode exception must remain visible');
            } catch (\RuntimeException $error) {
                self::assertSame('malformed decode', $error->getMessage());
            }
            self::assertCount(3, $invoker->calls);
            self::assertSame($command->name(), $invoker->calls[1][0]);
            self::assertSame([$command->name(), ['encoded-valid-payload']], $invoker->calls[2]);
        }
    }

    public function testConfigurationDefaultsAndBounds(): void
    {
        $configuration = new RunConfiguration();
        self::assertFalse($configuration->allowDeserializationMutations);
        self::assertSame(4097, $configuration->maxValueBytes);
        self::assertFalse($configuration->jsonSerialize()['allowDeserializationMutations']);
        $this->expectException(\InvalidArgumentException::class);
        new RunConfiguration(maxValueBytes: 1_048_577);
    }
}
