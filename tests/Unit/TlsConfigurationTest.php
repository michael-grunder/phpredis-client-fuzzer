<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\Options;
use Mgrunder\RedisClientTester\ClientConfiguration;
use Mgrunder\RedisClientTester\ClientType;
use Mgrunder\RedisClientTester\TlsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TlsConfigurationTest extends TestCase
{
    public function testTlsIsOptInAndPreservesVerificationDefaults(): void
    {
        $plain = Options::parse([], ['tls-options'], ['tls'])->tls();
        self::assertFalse($plain->enabled);
        self::assertSame('localhost', $plain->endpoint('localhost'));
        self::assertSame([], $plain->context());
        self::assertNull((new ClientConfiguration(type: ClientType::RedisCluster))->clusterContext());

        $tls = Options::parse(['--tls'], ['tls-options'], ['tls'])->tls();
        self::assertTrue($tls->enabled);
        self::assertSame([], $tls->options);
        foreach (['localhost', 'tcp://localhost', 'tls://localhost'] as $host) {
            self::assertSame('tls://localhost', $tls->endpoint($host));
        }
        self::assertSame('tls://[::1]:6380', $tls->endpoint('[::1]:6380'));
        self::assertSame([], (new ClientConfiguration(type: ClientType::RedisCluster, tls: $tls))->clusterContext());
    }

    public function testOptionsPreserveJsonTypesAndEnableTls(): void
    {
        $tls = Options::parse([
            '--tls-options={"cafile":"/tmp/ca.pem","verify_peer":false,"security_level":2,"peer_fingerprint":{"sha256":"abc"}}',
        ], ['tls-options'], ['tls'])->tls();
        self::assertTrue($tls->enabled);
        self::assertSame([
            'cafile' => '/tmp/ca.pem', 'verify_peer' => false, 'security_level' => 2,
            'peer_fingerprint' => ['sha256' => 'abc'],
        ], $tls->options);
        self::assertSame(['stream' => $tls->options], $tls->context());
        self::assertSame($tls->options, (new ClientConfiguration(type: ClientType::RedisCluster, tls: $tls))->clusterContext());
        self::assertSame($tls->context(), (new ClientConfiguration(type: ClientType::RelayCluster, tls: $tls))->clusterContext());
    }

    /** @return iterable<array{string}> */
    public static function invalidJson(): iterable
    {
        foreach (['[]', 'null', 'false', '"cafile"', '{bad', '{"0":"x"}', '{"":true}'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidJson')]
    public function testRejectsMalformedOptions(string $json): void
    {
        $this->expectException(\Exception::class);
        Options::parse(['--tls-options=' . $json], ['tls-options'], ['tls'])->tls();
    }

    public function testCannotSilentlyIgnoreTlsOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TlsConfiguration(options: ['cafile' => '/tmp/ca.pem']);
    }

    public function testRejectsTlsOnUnixSockets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TlsConfiguration(true))->endpoint('/tmp/redis.sock');
    }

    public function testExplicitTlsSeedsEnableEncryptionForDiscoveredPhpRedisNodes(): void
    {
        self::assertSame([], (new ClientConfiguration(
            type: ClientType::RedisCluster, seeds: ['tls://localhost:6380'],
        ))->clusterContext());
    }

    public function testFactoryAndReproductionUseAllFourClientConnectionApis(): void
    {
        // A separate PHP without extensions lets the factory call recording stand-ins for all four classes.
        $source = <<<'PHP'
namespace {
    class Redis {
        const OPT_PREFIX = 1, OPT_SERIALIZER = 2, OPT_COMPRESSION = 3, OPT_READ_TIMEOUT = 4;
        const SERIALIZER_NONE = 0, COMPRESSION_NONE = 0;
        public array $arguments;
        public function __construct(...$args) { $this->arguments = $args; }
        public function connect(...$args) { $this->arguments = $args; return true; }
        public function setOption(...$args) { return true; }
        public function getOption($option) { return $option === self::OPT_PREFIX ? '' : 0; }
        public function auth(...$args) { return true; }
        public function getAuth() { return ['user', 'secret']; }
        public function getHost() { return 'localhost'; }
        public function getTimeout() { return 2.0; }
        public function getPort() { return 6380; }
        public function getReadTimeout() { return 3.0; }
        public function close() { return true; }
        public function getLastError() { return null; }
        public function clearLastError() { return true; }
        public function isConnected() { return true; }
        public function _masters() { return [['localhost', 6380]]; }
    }
    class RedisCluster {
        use RecordingCluster;
    }
    trait RecordingCluster {
        public array $arguments;
        public function __construct(...$args) { $this->arguments = $args; }
        public function setOption(...$args) { return true; }
        public function getOption($option) { return $option === Redis::OPT_PREFIX ? '' : 0; }
        public function getAuth() { return ['user', 'secret']; }
        public function _masters() { return [['localhost', 6380]]; }
    }
}
namespace Relay {
    class Relay extends \Redis { const OPT_PHPREDIS_COMPATIBILITY = 99; }
    class Cluster { use \RecordingCluster; }
}
namespace Mgrunder\RedisClientTester\Differential {
    function stream_socket_client($address, &$errno, &$error, $timeout, $flags, $context) {
        $GLOBALS['neutral'] = [$address, stream_context_get_options($context)];
        throw new \RuntimeException('recorded');
    }
}
namespace {
    require $argv[1];
    $results = [];
    foreach (\Mgrunder\RedisClientTester\ClientType::cases() as $type) {
        $config = new \Mgrunder\RedisClientTester\ClientConfiguration(
            type: $type, host: 'localhost', port: 6380, seeds: ['localhost:6380'],
            timeout: 2.0, readTimeout: 3.0, auth: ['user', 'secret'],
            tls: new \Mgrunder\RedisClientTester\TlsConfiguration(true, ['cafile' => '/tmp/test-ca.pem']),
        );
        $client = (new \Mgrunder\RedisClientTester\ClientFactory())->create($config);
        $path = tempnam(sys_get_temp_dir(), 'tls-script-');
        \Mgrunder\RedisClientTester\ScriptLogger::init($path, clients: [$client]);
        \Mgrunder\RedisClientTester\ScriptLogger::finish();
        $results[$type->value] = [$client->arguments, file_get_contents($path)];
        if ($type === \Mgrunder\RedisClientTester\ClientType::Relay) {
            (new \Mgrunder\RedisClientTester\Commands\Command\close())->fuzz($client,
                new \Mgrunder\RedisClientTester\Commands\FuzzConfig());
            $results['reconnect'] = $client->arguments;
        }
        unlink($path);
    }
    $server = ['host' => 'tls://localhost', 'port' => 6380, 'timeout' => 1.0,
        'read_timeout' => 1.0, 'auth' => 'secret', 'tls_options' => ['cafile' => '/tmp/test-ca.pem']];
    foreach (['phpredis', 'relay'] as $kind) {
        $client = \Mgrunder\RedisClientTester\Differential\DiffRuntime::client($kind, $server,
            ['serializer' => 'none', 'compression' => 'none', 'relay_cache' => true]);
        $results['diff-' . $kind] = $client->arguments;
    }
    try { \Mgrunder\RedisClientTester\Differential\DiffRuntime::connect($server); }
    catch (\RuntimeException $e) { if ($e->getMessage() !== 'recorded') throw $e; }
    $results['neutral'] = $GLOBALS['neutral'];
    echo json_encode($results, JSON_THROW_ON_ERROR);
}
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-r', $source, dirname(__DIR__, 2) . '/vendor/autoload.php'], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors . (string) $output);
        self::assertIsString($output);
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($results);
        self::assertIsArray($results['relay']);
        self::assertSame($results['relay'][0], $results['reconnect']);
        self::assertSame(['tls://localhost:6380', ['ssl' => ['cafile' => '/tmp/test-ca.pem']]], $results['neutral']);
        foreach (['phpredis', 'relay'] as $kind) {
            self::assertIsArray($results['diff-' . $kind]);
            self::assertSame('tls://localhost', $results['diff-' . $kind][0]);
            self::assertSame(['stream' => ['cafile' => '/tmp/test-ca.pem']], $results['diff-' . $kind][6]);
        }
        foreach (ClientType::cases() as $type) {
            self::assertIsArray($results[$type->value]);
            [$args, $script] = $results[$type->value];
            self::assertIsArray($args);
            self::assertIsString($script);
            $stream = ['stream' => ['cafile' => '/tmp/test-ca.pem']];
            if ($type->isCluster()) {
                self::assertSame(['tls://localhost:6380'], $args[1]);
                self::assertSame(['user', 'secret'], $args[5]);
                self::assertSame($type === ClientType::RedisCluster ? $stream['stream'] : $stream, $args[6]);
            } else {
                self::assertSame('tls://localhost', $args[0]);
                self::assertSame(['auth' => ['user', 'secret']] + $stream, $args[6]);
                self::assertStringContainsString("(int)(\$opt['port'] ?? 6380)", $script);
            }
            self::assertStringContainsString('tls://localhost', $script);
            self::assertStringContainsString("'cafile' => '/tmp/test-ca.pem'", $script);
            self::assertSame($type !== ClientType::RedisCluster, str_contains($script, "'stream' =>"));
        }
    }
}
