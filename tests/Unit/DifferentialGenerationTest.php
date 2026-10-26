<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Differential\CaseGenerator;
use Mgrunder\PhpredisCommandFuzzer\Differential\GeneratorOptions;
use Mgrunder\PhpredisCommandFuzzer\Differential\Minimizer;
use Mgrunder\PhpredisCommandFuzzer\Differential\PhpLiteral;
use Mgrunder\PhpredisCommandFuzzer\Differential\ReproducerWriter;
use Mgrunder\PhpredisCommandFuzzer\Differential\Rng;
use Mgrunder\PhpredisCommandFuzzer\Differential\SignatureIndex;
use Mgrunder\PhpredisCommandFuzzer\Differential\TestCase as DiffCase;
use Mgrunder\PhpredisCommandFuzzer\Differential\ValueCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DifferentialGenerationTest extends TestCase
{
    private const OPTIONS = ['serializer' => 'none', 'compression' => 'none', 'relay_cache' => true];

    public function testGenerationIsDeterministicAndIgnoresGlobalRandomness(): void
    {
        $options = new GeneratorOptions(steps: 60, txnChance: 0.3, edgeChance: 0.5);

        mt_srand(1);
        $a = (new CaseGenerator($options))->generate(1234, 3, 'fuzz:t:');
        mt_srand(2);
        rand();
        $b = (new CaseGenerator($options))->generate(1234, 3, 'fuzz:t:');

        self::assertSame($a->toJson(), $b->toJson());
        self::assertNotSame($a->toJson(), (new CaseGenerator($options))->generate(1235, 3, 'fuzz:t:')->toJson());
    }

    public function testSmallerStepCountsYieldAPrefixOfTheSameCase(): void
    {
        for ($seed = 0; $seed < 20; $seed++) {
            $long = (new CaseGenerator(new GeneratorOptions(steps: 50, txnChance: 0.3)))->generate($seed, 0, 'fuzz:t:');
            $short = (new CaseGenerator(new GeneratorOptions(steps: 7, txnChance: 0.3)))->generate($seed, 0, 'fuzz:t:');

            self::assertSame($long->setup, $short->setup);
            self::assertSame($long->options, $short->options);
            self::assertSame($long->order, $short->order);
            self::assertSame(
                (new DiffCase(0, 0, 'fuzz:t:', [], array_slice($long->test, 0, 7), self::OPTIONS, ['phpredis', 'relay']))->toJson(),
                (new DiffCase(0, 0, 'fuzz:t:', [], $short->test, self::OPTIONS, ['phpredis', 'relay']))->toJson(),
            );
            self::assertCount(7, $short->test);
        }
    }

    public function testEveryGeneratedKeyStaysInsideThePrefix(): void
    {
        $generator = new CaseGenerator(new GeneratorOptions(steps: 80, edgeChance: 0.6, missingChance: 0.3, wrongTypeChance: 0.3, txnChance: 0.2));
        for ($seed = 0; $seed < 30; $seed++) {
            $case = $generator->generate($seed, $seed, 'fuzz:iso:');
            foreach ($case->setup as $command) {
                self::assertStringStartsWith('fuzz:iso:', $command[1]);
            }
            foreach ($case->test as [$method, $args]) {
                foreach (self::keyArguments($method, $args) as $key) {
                    self::assertStringStartsWith('fuzz:iso:', $key, "{$method} key outside the prefix");
                }
            }
        }
    }

    public function testFlushCommandsNeedExplicitOptIn(): void
    {
        $default = new CaseGenerator(new GeneratorOptions(steps: 200));
        $flushes = new CaseGenerator(new GeneratorOptions(steps: 200, allowFlush: true, commands: ['get', 'flushdb']));

        $seen = [];
        for ($seed = 0; $seed < 10; $seed++) {
            foreach ($default->generate($seed, 0, 'p:')->test as [$method]) {
                self::assertNotContains($method, ['flushdb', 'flushall']);
            }
            foreach ($flushes->generate($seed, 0, 'p:')->test as [$method]) {
                $seen[$method] = true;
            }
        }
        self::assertArrayHasKey('flushdb', $seen);
        self::assertNotContains('flushdb', CaseGenerator::resolveFilter([]));
        self::assertNotContains('flushall', CaseGenerator::resolveFilter(['@all']));
    }

    public function testFilterResolvesGroupsNamesAndExclusions(): void
    {
        $hash = CaseGenerator::resolveFilter(['@hash', '-hGetAll', 'GET']);
        self::assertContains('hSet', $hash);
        self::assertContains('get', $hash);
        self::assertNotContains('hGetAll', $hash);
        self::assertNotContains('sAdd', $hash);

        $this->expectException(\InvalidArgumentException::class);
        CaseGenerator::resolveFilter(['@nope']);
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (CaseGenerator::CATALOG as $name => [$group]) {
            if ($group !== 'txn' && $group !== 'flush') {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('operations')]
    public function testEveryOperationGeneratesClientCallableArguments(string $name): void
    {
        $generator = new CaseGenerator(new GeneratorOptions(steps: 30, edgeChance: 0.5, commands: [$name]));
        $case = $generator->generate(99, 0, 'p:');

        foreach ($case->test as [$method, $args, $mode]) {
            self::assertSame($name, $method);
            self::assertTrue(method_exists(\Redis::class, $method), "PhpRedis has no {$method}");
            self::assertMatchesRegularExpression('/^(exact|set|map|shape|ttl|pttl)$/', $mode);
        }
    }

    public function testTransactionBlocksAreWellFormedWithPerOperationExecModes(): void
    {
        $generator = new CaseGenerator(new GeneratorOptions(steps: 200, txnChance: 0.5));
        $blocks = 0;
        for ($seed = 0; $seed < 20; $seed++) {
            $case = $generator->generate($seed, 0, 'p:');
            self::assertTrue(Minimizer::wellFormed($case));
            $queued = [];
            $open = false;
            foreach ($case->test as [$method, , $mode]) {
                if ($method === 'multi' || $method === 'pipeline') {
                    $open = true;
                    $queued = [];
                } elseif ($method === 'exec') {
                    self::assertSame('exec:' . implode(',', $queued), $mode);
                    $open = false;
                    $blocks++;
                } elseif ($method !== 'discard' && $open) {
                    $queued[] = $mode;
                }
            }
        }
        self::assertGreaterThan(0, $blocks);

        $nested = new DiffCase(0, 0, 'p:', [], [['multi', [], 'exact'], ['pipeline', [], 'exact'], ['exec', [], 'exec:']], self::OPTIONS, ['relay', 'phpredis']);
        self::assertFalse(Minimizer::wellFormed($nested));
    }

    public function testCasesRoundTripThroughJsonWithBinaryAndSpecialFloats(): void
    {
        $case = new DiffCase(
            7,
            2,
            "p:\xff:",
            [['SET', "p:\xff:k", "a\0b"], ['ZADD', "p:\xff:z", '1.5', "\x80"]],
            [
                ['zAdd', ["p:\xff:z", NAN, 'm', INF, "\xfe"], 'exact'],
                ['set', ["p:\xff:k", -0.0, ['EX' => 100, 'NX']], 'exact'],
                ['hMset', ["p:\xff:h", [1 => 'a', 'b' => 1.0]], 'exact'],
                ['getRange', ["p:\xff:k", PHP_INT_MIN, PHP_INT_MAX], 'exact'],
            ],
            ['serializer' => 'json', 'compression' => 'lzf', 'relay_cache' => false],
            ['relay', 'phpredis'],
        );

        $copy = DiffCase::fromJson($case->toJson());

        self::assertSame($case->toJson(), $copy->toJson());
        self::assertSame($case->setup, $copy->setup);
        self::assertSame("p:\xff:", $copy->prefix);
        self::assertNan($copy->test[0][1][1]);
        self::assertSame(INF, $copy->test[0][1][3]);
        $zero = $copy->test[1][1][1];
        self::assertIsFloat($zero);
        self::assertSame(0.0, $zero);
        self::assertLessThan(0, fdiv(1.0, $zero));
        self::assertSame([1 => 'a', 'b' => 1.0], $copy->test[2][1][1]);
        self::assertSame(PHP_INT_MIN, $copy->test[3][1][1]);
    }

    public function testCasesRejectSetupOutsideThePrefixAndBadOrders(): void
    {
        try {
            new DiffCase(0, 0, 'p:', [['SET', 'other', 'x']], [], self::OPTIONS, ['phpredis', 'relay']);
            self::fail('setup outside the prefix must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new DiffCase(0, 0, 'p:', [], [], self::OPTIONS, ['relay', 'relay']);
    }

    public function testPhpLiteralsEvaluateToTheExactValues(): void
    {
        $values = ["a\0b\xff\$x\"'\\", '', PHP_INT_MIN, PHP_INT_MAX, -0.0, 1e308, 5e-324, 0.1, INF, -INF, true, null,
            ['k' => [1, 2.5], 3 => 'x'], str_repeat("\0", 100), str_repeat('x', 5000)];
        foreach ($values as $value) {
            $copy = eval('return ' . PhpLiteral::export($value) . ';');
            self::assertSame(serialize($value), serialize($copy));
        }
        self::assertNan(eval('return ' . PhpLiteral::export(NAN) . ';'));
    }

    public function testValueCodecKeepsListsAndMapsDistinct(): void
    {
        self::assertSame([1 => 'a'], ValueCodec::decode(ValueCodec::encode([1 => 'a'])));
        self::assertSame(['a'], ValueCodec::decode(ValueCodec::encode(['a'])));
        self::assertSame(1.0, ValueCodec::decode(json_decode((string) json_encode(ValueCodec::encode(1.0)), true)));
    }

    public function testRngStreamsAreIndependentAndReplayable(): void
    {
        $a = Rng::derive(5, 'setup');
        $b = Rng::derive(5, 'setup');
        $c = Rng::derive(5, 'test');
        $seqA = array_map(static fn (): int => $a->int(0, 1_000_000), range(1, 10));
        self::assertSame($seqA, array_map(static fn (): int => $b->int(0, 1_000_000), range(1, 10)));
        self::assertNotSame($seqA, array_map(static fn (): int => $c->int(0, 1_000_000), range(1, 10)));
        self::assertGreaterThanOrEqual(0, Rng::mix(PHP_INT_MAX, 'x'));
    }

    public function testReproducerIsStandaloneValidPhp(): void
    {
        $generated = (new CaseGenerator(new GeneratorOptions(steps: 40, edgeChance: 0.6, txnChance: 0.3)))->generate(3, 1, 'fuzz:r:');
        $server = ['host' => 'tls://localhost', 'tls_options' => ['cafile' => '/tmp/ca.pem', 'verify_peer' => true], 'port' => 6380, 'timeout' => 1.0, 'read_timeout' => 1.0, 'auth' => null, 'ttl_tolerance_ms' => 2000, 'strict_messages' => false];
        $source = ReproducerWriter::source($generated, $server, ['signature' => 'reply:get:x */ y']);

        self::assertStringNotContainsString('namespace ', $source);
        self::assertStringNotContainsString('Mgrunder', $source);
        self::assertStringContainsString('exit(DiffRuntime::main($case, $server));', $source);
        self::assertStringContainsString('reply:get:x * / y', $source);
        self::assertStringContainsString("'host' => 'tls://localhost'", $source);
        self::assertStringContainsString("'cafile' => '/tmp/ca.pem'", $source);
        self::assertStringContainsString("'verify_peer' => true", $source);

        $file = tempnam(sys_get_temp_dir(), 'diffrepro');
        self::assertIsString($file);
        try {
            file_put_contents($file, $source);
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));

            // The hard-coded case must be exactly the generated one.
            $body = (string) preg_replace('/^exit\(DiffRuntime::main.*$/ms', 'return $case;', substr($source, 5));
            $literal = eval($body);
            self::assertIsArray($literal);
            $runtime = $generated->runtime();
            ksort($runtime);
            ksort($literal);
            self::assertSame(serialize($runtime), serialize($literal));
        } finally {
            @unlink($file);
        }
    }

    public function testSignatureIndexSharesSignaturesThroughItsFile(): void
    {
        $file = sys_get_temp_dir() . '/diff-signatures-' . getmypid() . '-' . hrtime(true);
        try {
            $first = new SignatureIndex($file);
            self::assertFalse($first->seen('reply:get:x'));
            $first->add('reply:get:x', '/tmp/a');

            $second = new SignatureIndex($file);
            self::assertTrue($second->seen('reply:get:x'));
            self::assertFalse($second->seen('reply:get:y'));
            self::assertFalse((new SignatureIndex($file, false))->seen('reply:get:x'));
        } finally {
            @unlink($file);
        }
    }

    /**
     * Arguments that name keys: strings with the key naming scheme, including
     * inside key lists and mset maps.
     *
     * @param list<mixed> $args
     * @return list<string>
     */
    private static function keyArguments(string $method, array $args): array
    {
        $keys = [];
        $walk = static function (mixed $value) use (&$walk, &$keys): void {
            if (is_string($value) && preg_match('/:(k\d+|m\d+)/', $value) === 1 && str_contains($value, 'fuzz:')) {
                $keys[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $walk($key);
                    }
                    $walk($item);
                }
            }
        };
        if ($method !== 'object') {
            $walk($args[0] ?? null);
        }
        foreach (array_slice($args, 1) as $arg) {
            $walk($arg);
        }

        return $keys;
    }
}
