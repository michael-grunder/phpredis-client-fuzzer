<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\ClientInvoker;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\Commands\ZAddArgs;
use Mgrunder\PhpredisCommandFuzzer\Commands\ZRangeArgs;
use Mgrunder\PhpredisCommandFuzzer\ScriptArg;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class ZSetRecordingInvoker implements ClientInvoker
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    public function reset(): void
    {
        $this->calls = [];
    }

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        $this->calls[] = [$method, $arguments];

        return true;
    }
}

final class ZSetCommandTest extends TestCase
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

    /** @return iterable<string, array{string}> */
    public static function zaddVariants(): iterable
    {
        foreach ([...ZAddArgs::VALID_VARIANTS, ...ZAddArgs::EDGE_VARIANTS] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('zaddVariants')]
    public function testZaddCorpusHasCompletePairsAndReproducibleArguments(string $variant): void
    {
        $config = new FuzzConfig();
        $args = new ZAddArgs($config, $variant);
        $normal = $args->arguments(false);
        if ($variant !== 'plain') {
            array_shift($normal);
        }
        self::assertGreaterThanOrEqual(2, count($normal));
        self::assertSame(0, count($normal) % 2);
        foreach (array_chunk($normal, 2) as [$score, $member]) {
            self::assertTrue(is_float($score) || is_string($score));
            self::assertIsString($member);
        }
        if (str_starts_with($variant, 'incr') && $variant !== 'incr-many-invalid') {
            self::assertCount(2, $normal);
        }
        if ($variant === 'duplicate-member') {
            self::assertSame($normal[1], $normal[3]);
            self::assertNotSame($normal[0], $normal[2]);
        }
        if ($variant === 'equal-scores') {
            self::assertSame($normal[0], $normal[2]);
            self::assertNotSame($normal[1], $normal[3]);
        }
        if (str_ends_with($variant, '-missing')) {
            self::assertNull($args->initialScore);
        }
        if (str_ends_with($variant, '-present')) {
            self::assertSame(10.0, $args->initialScore);
        }
        mt_srand(42);
        $repeat = new ZAddArgs($config, $variant);
        // serialize preserves NAN and the signed zero without equality pitfalls.
        self::assertSame(serialize($args->arguments(true)), serialize($repeat->arguments(true)));
    }

    public function testZaddReferenceOptionsArePreservedInExecutableReproduction(): void
    {
        $args = (new ZAddArgs(new FuzzConfig(), 'referenced-options'))->arguments(false);
        $options = $args[0];
        if (!$options instanceof ScriptArg) {
            self::fail('Referenced options require a ScriptArg');
        }
        $runtime = $options->value();
        $replayed = eval('return ' . $options->code() . ';');
        foreach ([$runtime, $replayed] as $value) {
            if (!is_array($value)) {
                self::fail('Reference options must evaluate to an array');
            }
            self::assertSame(['CH', 'CH'], $value);
            $value[0] = 'NX';
            self::assertSame('NX', $value[1]);
        }
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
    public function testZaddSetupUsesTheSelectedSurfaceAndClusterSlot(string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional client extension is not loaded');
        }
        $client = $this->createStub($class);
        $cluster = $client instanceof RedisCluster || $client instanceof Cluster;
        $config = (new FuzzConfig())->setCluster($cluster)->setShards(8);
        $command = new Command\zadd();
        $invoker = new ZSetRecordingInvoker();
        $command->setClientInvoker($invoker);
        $forms = [];
        foreach ([false, true] as $raw) {
            for ($i = 0; $i < 400; $i++) {
                $invoker->reset();
                $config->beginCommand($command, $raw);
                $raw ? $command->fuzzRaw($client, $config) : $command->fuzz($client, $config);
                $calls = $invoker->calls;
                self::assertContains(count($calls), [1, 2, 3]);
                $keys = [];
                foreach ($calls as [$method, $arguments]) {
                    if ($raw) {
                        self::assertSame('rawCommand', $method);
                        if ($cluster) {
                            $route = array_shift($arguments);
                            if (!is_string($route)) {
                                self::fail('Expected a raw routing key');
                            }
                            $keys[] = $route;
                        }
                        $method = array_shift($arguments);
                    }
                    self::assertIsString($method);
                    self::assertContains(strtolower($method), ['zadd', 'zrem']);
                    $key = $arguments[0];
                    if (!is_string($key)) {
                        self::fail('Expected a ZADD key');
                    }
                    $keys[] = $key;
                    $forms[($raw ? 'raw' : 'method') . ':' . count($calls)] = true;
                }
                if ($cluster) {
                    $tags = array_map($this->tag(...), $keys);
                    self::assertCount(1, array_unique($tags));
                } else {
                    self::assertCount(1, array_unique($keys));
                }
                if (count($calls) > 1) {
                    self::assertSame($raw ? 'ZREM' : 'zRem', $raw ? $calls[0][1][$cluster ? 1 : 0] : $calls[0][0]);
                }
            }
        }
        foreach (['raw:1', 'raw:2', 'raw:3', 'method:1', 'method:2', 'method:3'] as $form) {
            self::assertArrayHasKey($form, $forms);
        }
    }

    public function testRangeOptionsAgreeAcrossSurfacesAndKeepRankBoundsNumeric(): void
    {
        $seen = [];
        foreach ([true, false] as $withScores) {
            for ($i = 0; $i < 600; $i++) {
                $args = new ZRangeArgs(new FuzzConfig(), $withScores);
                $options = $args->options();
                $raw = $args->optionsRaw();
                $by = in_array('BYLEX', $raw, true) ? 'BYLEX' : (in_array('BYSCORE', $raw, true) ? 'BYSCORE' : 'BYRANK');
                $seen[$by] = true;
                if ($by === 'BYRANK') {
                    self::assertIsInt($args->start());
                    self::assertIsInt($args->end());
                    self::assertArrayNotHasKey('LIMIT', $options);
                } else {
                    self::assertIsString($args->start());
                    self::assertIsString($args->end());
                }
                self::assertSame(isset($options['WITHSCORES']), in_array('WITHSCORES', $raw, true));
                if (!$withScores) {
                    self::assertNotContains('WITHSCORES', $raw);
                }
                if (isset($options['LIMIT'])) {
                    $position = array_search('LIMIT', $raw, true);
                    if ($position === false || !is_array($options['LIMIT'])) {
                        self::fail('LIMIT must be a top-level named option and a raw token');
                    }
                    self::assertSame($options['LIMIT'], array_slice($raw, $position + 1, 2));
                    self::assertGreaterThanOrEqual(0, $options['LIMIT'][0]);
                    self::assertGreaterThanOrEqual(-1, $options['LIMIT'][1]);
                    $seen['limit:' . $options['LIMIT'][1]] = true;
                }
                foreach (['REV', 'WITHSCORES'] as $token) {
                    if (in_array($token, $raw, true)) {
                        $seen[$token] = true;
                    }
                }
            }
        }
        foreach (['BYRANK', 'BYSCORE', 'BYLEX', 'REV', 'WITHSCORES', 'limit:-1', 'limit:0'] as $token) {
            self::assertArrayHasKey($token, $seen);
        }
    }

    public function testRawMultiKeyGrammarAndSafetyFlags(): void
    {
        $client = new Redis();
        $config = (new FuzzConfig())->setCluster(true)->setShards(8)->setMaxKeys(4);
        $registry = new Registry();
        foreach (['zdiff', 'zdiffstore', 'zinter', 'zunion', 'zinterstore', 'zunionstore', 'zintercard', 'zmpop', 'bzmpop', 'zrangestore'] as $name) {
            $command = $registry->get($name);
            if (!$command instanceof Command || !$command instanceof \Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface) {
                self::fail('Missing raw ZSET command');
            }
            self::assertSame(Command::ZSET, $command->type());
            self::assertSame(0, $command->flags() & Command::CROSSSLOT);
            $write = str_ends_with($name, 'store') || str_ends_with($name, 'mpop');
            self::assertNotSame(0, $command->flags() & ($write ? Command::WRITE : Command::READ));
            self::assertSame($name === 'bzmpop', (bool) ($command->flags() & Command::BLOCKING));
            $invoker = new ZSetRecordingInvoker();
            $command->setClientInvoker($invoker);
            $seen = [];
            for ($i = 0; $i < 300; $i++) {
                $invoker->reset();
                $config->beginCommand($command, true);
                $command->fuzzRaw($client, $config);
                $args = $invoker->calls[0][1];
                self::assertSame($name, array_shift($args));
                $keys = [];
                if (str_ends_with($name, 'store')) {
                    $keys[] = array_shift($args);
                }
                if ($name === 'zrangestore') {
                    $keys[] = array_shift($args);
                    $bounds = array_splice($args, 0, 2);
                    self::assertCount(2, $bounds);
                } else {
                    if ($name === 'bzmpop') {
                        self::assertIsFloat(array_shift($args));
                    }
                    $count = array_shift($args);
                    if (!is_int($count) || $count < 1) {
                        self::fail('Missing numkeys');
                    }
                    $keys = [...$keys, ...array_splice($args, 0, $count)];
                }
                self::assertCount(1, array_unique(array_map($this->tag(...), $keys)));
                if (str_ends_with($name, 'mpop')) {
                    self::assertContains(array_shift($args), ['MIN', 'MAX']);
                }
                while ($args !== []) {
                    $token = array_shift($args);
                    if (!is_string($token)) {
                        self::fail('Expected an option token');
                    }
                    $seen[$token] = true;
                    if ($token === 'LIMIT') {
                        $length = $name === 'zrangestore' ? 2 : 1;
                        self::assertCount($length, array_splice($args, 0, $length));
                    } elseif ($token === 'COUNT') {
                        self::assertIsInt(array_shift($args));
                    } elseif ($token === 'AGGREGATE') {
                        self::assertContains(array_shift($args), ['MIN', 'MAX', 'SUM', 'COUNT']);
                    } elseif ($token === 'WEIGHTS') {
                        $weights = array_splice($args, 0, count($keys) - (str_ends_with($name, 'store') ? 1 : 0));
                        self::assertNotEmpty($weights);
                        foreach ($weights as $weight) {
                            self::assertIsFloat($weight);
                        }
                    } else {
                        self::assertContains($token, ['WITHSCORES', 'BYSCORE', 'BYLEX', 'REV']);
                    }
                    if ($write) {
                        self::assertNotSame('WITHSCORES', $token);
                    }
                }
            }
            $expected = match ($name) {
                'zinter', 'zunion' => ['WEIGHTS', 'AGGREGATE', 'WITHSCORES'],
                'zinterstore', 'zunionstore' => ['WEIGHTS', 'AGGREGATE'],
                'zintercard' => ['LIMIT'],
                'zmpop', 'bzmpop' => ['COUNT'],
                'zrangestore' => ['BYSCORE', 'BYLEX', 'REV', 'LIMIT'],
                'zdiff' => ['WITHSCORES'],
                default => [],
            };
            foreach ($expected as $token) {
                self::assertArrayHasKey($token, $seen, $name);
            }
        }
    }

    /** @param class-string<Redis|RedisCluster|Relay|Cluster> $class */
    #[DataProvider('clients')]
    public function testRankOnlyUsesWithScoreWhenTheMethodSupportsIt(string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional client extension is not loaded');
        }
        $client = $this->createStub($class);
        foreach ([new Command\zrank(), new Command\zrevrank()] as $command) {
            $invoker = new ZSetRecordingInvoker();
            $command->setClientInvoker($invoker);
            $reflection = new \ReflectionMethod($client, $command->name());
            $supportsScore = $reflection->getNumberOfParameters() >= 3 || $reflection->isVariadic();
            $seen = [];
            for ($i = 0; $i < 100; $i++) {
                $command->fuzz($client, new FuzzConfig());
                $command->fuzzRaw($client, new FuzzConfig());
            }
            foreach ($invoker->calls as [$method, $args]) {
                if ($method === 'rawCommand') {
                    if ($client instanceof RedisCluster || $client instanceof Cluster) {
                        array_shift($args);
                    }
                    self::assertSame($command->name(), array_shift($args));
                    self::assertContains(count($args), [2, 3]);
                    if (count($args) === 3) {
                        self::assertSame('WITHSCORE', $args[2]);
                        $seen['raw-withscore'] = true;
                    }
                } else {
                    self::assertContains(count($args), $supportsScore ? [2, 3] : [2]);
                    if (count($args) === 3) {
                        self::assertIsBool($args[2]);
                        $seen[$args[2] ? 'true' : 'false'] = true;
                    } else {
                        $seen['omitted'] = true;
                    }
                }
            }
            self::assertArrayHasKey('raw-withscore', $seen);
            self::assertArrayHasKey('omitted', $seen);
            if ($supportsScore) {
                self::assertArrayHasKey('true', $seen);
                self::assertArrayHasKey('false', $seen);
            }
        }
    }

    public function testRandomMemberCoversSignedCountsAndOptionalReplyShapes(): void
    {
        $command = new Command\zrandmember();
        $invoker = new ZSetRecordingInvoker();
        $command->setClientInvoker($invoker);
        $client = new Redis();
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $command->fuzz($client, new FuzzConfig());
            $command->fuzzRaw($client, new FuzzConfig());
        }
        foreach ($invoker->calls as [$method, $args]) {
            $raw = $method === 'rawCommand';
            if ($raw) {
                array_shift($args);
            }
            array_shift($args);
            $surface = $raw ? 'raw:' : 'method:';
            if ($args === []) {
                $seen[$surface . 'omitted'] = true;
                continue;
            }
            if ($raw) {
                $count = array_shift($args);
                if ($args !== []) {
                    self::assertSame(['WITHSCORES'], $args);
                    $seen[$surface . 'withscores'] = true;
                }
            } else {
                $options = $args[0];
                if ($options === null || $options === []) {
                    $seen[$surface . ($options === null ? 'null' : 'empty')] = true;
                    continue;
                }
                if (!is_array($options)) {
                    self::fail('Expected options array');
                }
                if (isset($options['WITHSCORES'])) {
                    self::assertIsBool($options['WITHSCORES']);
                    $seen[$surface . ($options['WITHSCORES'] ? 'withscores' : 'without-scores')] = true;
                }
                if (($options['WITHSCORES'] ?? false) === true) {
                    $seen[$surface . 'withscores'] = true;
                }
                if (!isset($options['COUNT'])) {
                    continue;
                }
                $count = $options['COUNT'];
            }
            if (!is_int($count)) {
                self::fail('COUNT must be an integer');
            }
            $seen[$surface . ($count < 0 ? 'negative' : ($count === 0 ? 'zero' : 'positive'))] = true;
        }
        foreach (['method:', 'raw:'] as $surface) {
            foreach (['omitted', 'negative', 'zero', 'positive', 'withscores'] as $form) {
                self::assertArrayHasKey($surface . $form, $seen);
            }
        }
        self::assertArrayHasKey('method:null', $seen);
        self::assertArrayHasKey('method:empty', $seen);
        self::assertArrayHasKey('method:without-scores', $seen);
    }

    public function testLegacyRangeOptionsOnlyReceiveRankBounds(): void
    {
        $command = new Command\zrange();
        $invoker = new ZSetRecordingInvoker();
        $command->setClientInvoker($invoker);
        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $command->fuzz(new Redis(), new FuzzConfig());
        }
        foreach ($invoker->calls as [, $args]) {
            $options = $args[3] ?? null;
            if (!is_array($options) || $options === []) {
                self::assertIsInt($args[1]);
                self::assertIsInt($args[2]);
                $seen[serialize(array_slice($args, 3))] = true;
            }
        }
        foreach ([[], [null], [false], [true], [[]]] as $form) {
            self::assertArrayHasKey(serialize($form), $seen);
        }
    }

    public function testZaddLogsEverySetupCallAndVariantAndRepeatsWithTheSeed(): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getHost')->willReturn('server-free');
        $client->method('getPort')->willReturn(6379);
        $path = tempnam(sys_get_temp_dir(), 'zset-reproduction-');
        if ($path === false) {
            self::fail('Cannot create reproduction log');
        }
        try {
            $invoker = new ZSetRecordingInvoker();
            $command = new Command\zadd();
            $command->setClientInvoker($invoker);
            ScriptLogger::init($path, clients: [$client]);
            $snapshots = [];
            for ($repeat = 0; $repeat < 2; $repeat++) {
                mt_srand(42);
                $invoker->reset();
                for ($i = 0; $i < 500; $i++) {
                    $command->fuzz($client, new FuzzConfig());
                }
                $snapshots[] = serialize($invoker->calls);
            }
            ScriptLogger::finish();
            self::assertSame($snapshots[0], $snapshots[1]);
            $script = file_get_contents($path);
            if ($script === false) {
                self::fail('Cannot read reproduction log');
            }
            self::assertSame(1000, substr_count($script, 'ZADD variant:'));
            preg_match_all('/->(zadd|zrem)\(/i', $script, $matches);
            self::assertCount(count($invoker->calls) * 2, $matches[0]);
            self::assertStringContainsString('return [&$option, &$option]', $script);
            self::assertStringContainsString('ZADD variant: incr-many-invalid', $script);
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }

    private function tag(mixed $key): string
    {
        if (!is_string($key) || preg_match('/\{([^}]+)\}/', $key, $matches) !== 1) {
            self::fail('Expected a generated hash-tagged key');
        }

        return $matches[1];
    }
}
