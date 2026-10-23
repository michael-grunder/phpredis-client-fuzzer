<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\HarnessApplication;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use PHPUnit\Framework\TestCase;

final class HarnessApplicationTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testMissingFuzzerScriptIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--runs', '1',
            '--',
            'bin/phpredis-fuz', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/phpredis-fuz',
            self::contents($error),
        );
    }

    public function testMissingScriptIsDetectedBehindAnExplicitPhpInterpreter(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--',
            'php', 'bin/phpredis-fuz',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'fuzzer script not found: bin/phpredis-fuz',
            self::contents($error),
        );
    }

    public function testMissingPhpIniIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--php-ini', '/nonexistent/phpredis-fuzz.ini',
            '--runs', '1',
            '--',
            'bin/phpredis-fuzz', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'php ini file not found: /nonexistent/phpredis-fuzz.ini',
            self::contents($error),
        );
    }

    public function testAnUnwritableRunLogIsRejectedBeforeSpawningAnything(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run([
            '--run-log', '/nonexistent/directory/runs.log',
            '--output', $this->workspace(),
            '--no-core-check',
            '--runs', '1',
            '--',
            'bin/phpredis-fuzz', '--steps', '{steps}',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'cannot open --run-log for appending: /nonexistent/directory/runs.log',
            self::contents($error),
        );
    }

    public function testHelpDocumentsTheRunLog(): void
    {
        $output = self::stream();

        $status = (new HarnessApplication($output, self::stream()))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('--run-log FILE', self::contents($output));
    }

    public function testUnavailableTargetAbortsBeforeCreatingCampaignArtifacts(): void
    {
        $root = $this->workspace();
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--no-core-check', '--port', '6379', '7000',
            '--output', $root . '/out', '--run-log', $root . '/runs.log',
            '--', 'bin/phpredis-fuzz', '--port={port}', '--host=' . $root . '/missing.sock',
        ]);

        self::assertSame(1, $status);
        $diagnostic = self::contents($error);
        self::assertStringContainsString('Redis/Valkey startup check failed', $diagnostic);
        self::assertStringContainsString($root . '/missing.sock:6379:', $diagnostic);
        self::assertStringContainsString($root . '/missing.sock:7000:', $diagnostic);
        self::assertStringContainsString('No fuzzing jobs were started.', $diagnostic);
        self::assertDirectoryDoesNotExist($root);
    }

    public function testHelpDocumentsThePhpStartupOptions(): void
    {
        $output = self::stream();

        $status = (new HarnessApplication($output, self::stream()))->run(['--help']);

        self::assertSame(0, $status);
        $help = self::contents($output);
        self::assertStringContainsString('--php-args ARGS', $help);
        self::assertStringContainsString('--php-ini FILE', $help);
        self::assertStringContainsString('{a,b,c}', $help);
        self::assertStringContainsString('{0.0-100.0}', $help);
    }

    public function testInvalidStartupRangesAreRejectedBeforeLaunchingWorkers(): void
    {
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--php-args=-d precision={9-1}',
            '--', \dirname(__DIR__) . '/Fixtures/startup-child.php',
        ]);
        self::assertSame(1, $status);
        self::assertStringContainsString('reversed startup range', self::contents($error));
    }

    public function testCampaignExpandsStartupArgumentsAndIniForProbesAndWorkers(): void
    {
        $root = $this->workspace();
        Fs::ensureDir($root);
        $ini = $root . '/template.ini';
        file_put_contents($ini, "precision={4-4}\n");
        $error = self::stream();
        $status = (new HarnessApplication(self::stream(), $error))->run([
            '--php-ini', $ini,
            '--php-args=-d serialize_precision={10-10}',
            '--no-core-check', '--keep-work', '--no-tui',
            '--seed', '123', '--runs', '1', '--jobs', '1',
            '--output', $root . '/out', '--run-log', $root . '/runs.log',
            '--', \dirname(__DIR__) . '/Fixtures/startup-child.php',
        ]);
        self::assertSame(0, $status, self::contents($error));
        $log = (string) file_get_contents($root . '/runs.log');
        self::assertStringContainsString('"precision":"4"', $log);
        self::assertStringContainsString('"serialize_precision":"10"', $log);
        self::assertSame("precision=4\n", file_get_contents($root . '/out/.work/' . getmypid() . '/probe/php.ini'));
        self::assertSame("precision=4\n", file_get_contents($root . '/out/.work/' . getmypid() . '/run-00001/php.ini'));
        self::assertSame("precision={4-4}\n", file_get_contents($ini));
    }

    public function testHelpDocumentsTheStartupFailureExitCode(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new HarnessApplication($output, $error))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('A run that exits 78 is a startup failure', self::contents($output));
        self::assertSame('', self::contents($error));
    }

    public function testMemoryCaptureCountsAsAReproducerAndReducesOnlyMemoryFailures(): void
    {
        $root = $this->workspace();
        $output = self::stream();
        $error = self::stream();
        $status = (new HarnessApplication($output, $error))->run([
            '--capture', 'memory', '--reduce', 'steps', '--steps', '8',
            '--php-args=-d display_errors=stderr -d log_errors=0 -d html_errors=0',
            '--no-core-check', '--no-tui', '--quiet',
            '--seed', '123', '--reproducers', '1', '--jobs', '1',
            '--output', $root . '/out',
            '--', \dirname(__DIR__) . '/Fixtures/memory-child.php', '{steps}',
        ]);
        self::assertSame(1, $status, self::contents($error));
        $captures = glob($root . '/out/memory/*/minimized/meta.json');
        self::assertIsArray($captures);
        self::assertCount(1, $captures);
        $meta = json_decode((string) file_get_contents($captures[0]), true);
        self::assertIsArray($meta);
        self::assertSame(3, $meta['steps']);
        self::assertSame('memory', $meta['kind']);
    }

    private function workspace(): string
    {
        $this->root = sys_get_temp_dir() . '/phpredis-fuzz-harness-app-' . getmypid() . '-' . mt_rand();

        return $this->root;
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
