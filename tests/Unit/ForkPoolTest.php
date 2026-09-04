<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\ExitCode;
use Mgrunder\PhpredisCommandFuzzer\Cli\ForkPool;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pcntl')]
final class ForkPoolTest extends TestCase
{
    public function testEveryChildResultIsCollectedWhole(): void
    {
        $output = self::stream();
        $error = self::stream();

        // Larger than a pipe buffer, so a child writing straight to the shared
        // output stream would be free to interleave with its siblings.
        $size = 256 * 1024;

        $status = (new ForkPool($output, $error))->run(
            3,
            static function (int $index, $stream) use ($size): int {
                for ($written = 0; $written < $size; $written += 4096) {
                    fwrite($stream, str_repeat((string) $index, 4096));
                    usleep(100);
                }

                return ExitCode::SUCCESS;
            },
        );

        $contents = self::contents($output);

        self::assertSame(ExitCode::SUCCESS, $status);
        self::assertSame('', self::contents($error));
        self::assertSame($size * 3, strlen($contents));
        foreach (['0', '1', '2'] as $index) {
            self::assertStringContainsString(str_repeat($index, $size), $contents);
        }
    }

    public function testChildIndexesAreZeroBasedAndSequential(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new ForkPool($output, $error))->run(
            4,
            static function (int $index, $stream): int {
                fwrite($stream, "child {$index}\n");

                return ExitCode::SUCCESS;
            },
        );

        $lines = explode("\n", trim(self::contents($output)));
        sort($lines);

        self::assertSame(ExitCode::SUCCESS, $status);
        self::assertSame(['child 0', 'child 1', 'child 2', 'child 3'], $lines);
    }

    public function testTheMostSevereChildStatusIsReturned(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new ForkPool($output, $error))->run(
            3,
            static fn (int $index, $stream): int => match ($index) {
                0 => ExitCode::SUCCESS,
                1 => ExitCode::FAILURE,
                default => ExitCode::STARTUP,
            },
        );

        self::assertSame(ExitCode::STARTUP, $status);
        self::assertSame('', self::contents($error));
    }

    public function testAThrowingChildFailsTheRunAndIsReported(): void
    {
        $output = self::stream();
        // A child reports through the inherited descriptor, so this one has to
        // be a real file rather than per-process memory.
        $error = self::fileStream();

        $status = (new ForkPool($output, $error))->run(
            1,
            static function (int $index, $stream): int {
                throw new \RuntimeException('the child could not connect');
            },
        );

        self::assertSame(ExitCode::FAILURE, $status);
        self::assertStringContainsString(
            'child 1: the child could not connect',
            self::contents($error),
        );
    }

    #[RequiresPhpExtension('posix')]
    public function testASignalledChildFailsTheRunAndNamesTheSignal(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new ForkPool($output, $error))->run(
            1,
            static function (int $index, $stream): int {
                fwrite($stream, "started\n");
                fflush($stream);
                posix_kill(posix_getpid(), SIGKILL);

                return ExitCode::SUCCESS;
            },
        );

        self::assertSame(ExitCode::FAILURE, $status);
        self::assertSame("started\n", self::contents($output));
        self::assertStringContainsString(
            'terminated by signal ' . SIGKILL,
            self::contents($error),
        );
    }

    /** @return resource */
    private static function fileStream()
    {
        $stream = tmpfile();
        self::assertIsResource($stream);

        return $stream;
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
