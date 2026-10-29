<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Sapi\ServerDiscovery;
use PHPUnit\Framework\TestCase;

final class ServerDiscoveryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('posix is required for SAPI discovery');
        }
        $this->directory = sys_get_temp_dir() . '/sapi-discovery-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            Fs::removeTree($this->directory);
        }
    }

    public function testLatestChangesWithoutChangingEarlierIds(): void
    {
        $discovery = new ServerDiscovery($this->directory);
        $first = '/tmp/long path/first';
        $second = '/tmp/long path/second';
        $discovery->publish($first);
        $id = $discovery->id($first);
        self::assertSame($first . "\n", file_get_contents($this->directory . '/' . $id));
        self::assertSame(0o600, fileperms($this->directory . '/' . $id) & 0o777);
        $discovery->publish($second);
        self::assertSame($second, $discovery->resolve());
        self::assertSame($first, $discovery->resolve($id));
    }

    public function testMissingLatestExplainsHowToConnect(): void
    {
        $this->expectExceptionMessage('start serve or pass --directory');
        (new ServerDiscovery($this->directory))->resolve();
    }

    public function testIdCannotTraverseDirectories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ServerDiscovery($this->directory))->resolve('../something');
    }

    public function testPublishingDoesNotFollowLatestSymlink(): void
    {
        $discovery = new ServerDiscovery($this->directory);
        $evidence = $this->directory . '/evidence';
        file_put_contents($evidence, 'keep');
        symlink($evidence, $discovery->latestPath());
        try {
            $discovery->publish('/tmp/server');
            self::fail('expected rejection of symlink');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unsafe SAPI server pointer', $error->getMessage());
            self::assertSame('keep', file_get_contents($evidence));
        }
    }
}
