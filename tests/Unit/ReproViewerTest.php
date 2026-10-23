<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\ReproViewerApplication;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Viewer\Backtrace;
use Mgrunder\PhpredisCommandFuzzer\Viewer\Reproducer;
use Mgrunder\PhpredisCommandFuzzer\Viewer\SanitizerAnalysis;
use PHPUnit\Framework\TestCase;

final class ReproViewerTest extends TestCase
{
    private const UAF = <<<'TXT'
=================================================================
==1359001==ERROR: AddressSanitizer: heap-use-after-free on address 0x7d07b2870b50 at pc 0x7be7ae1f2a6f bp 0x7ffd74b94ee0 sp 0x7ffd74b94ed8
READ of size 8 at 0x7d07b2870b50 thread T0
    #0 0x7be7ae1f2a6e in relayTryGetLong /tmp/build/relay/src/commands.c:2936
    #1 0x7be7ae274c4a in relayRawHRandFieldRCmd /tmp/build/relay/src/commands.c:12841
    #2 0x7be7ae2f46a0 in relayRawCommand /tmp/build/relay/src/commands.c:16383
    #3 0x55b0e0d89152 in execute_ex (/usr/bin/php8.5+0x589152) (BuildId: f5c08b5172209deeb1141c4a4fe9c48706660d65)
    #4 0x55b0e0de9d74  (/usr/bin/php8.5+0x5e9d74) (BuildId: f5c08b5172209deeb1141c4a4fe9c48706660d65)
    #5 0x7fe7b2d2bf74 in __libc_start_call_main ../sysdeps/nptl/libc_start_call_main.h:58

0x7d07b2870b50 is located 16 bytes inside of 320-byte region [0x7d07b2870b40,0x7d07b2870c80)
freed by thread T0 here:
    #0 0x7fe7b3b2340f in free ../../../../src/libsanitizer/asan/asan_malloc_linux.cpp:51
    #1 0x55b0e0d80239 in execute_ex (/usr/bin/php8.5+0x580239) (BuildId: f5c08b5172209deeb1141c4a4fe9c48706660d65)

previously allocated by thread T0 here:
    #0 0x7fe7b3b2435f in malloc ../../../../src/libsanitizer/asan/asan_malloc_linux.cpp:67
    #1 0x55b0e0ce728a  (/usr/bin/php8.5+0x4e728a) (BuildId: f5c08b5172209deeb1141c4a4fe9c48706660d65)

SUMMARY: AddressSanitizer: heap-use-after-free /tmp/build/relay/src/commands.c:2936 in relayTryGetLong
Shadow bytes around the buggy address:
=>0x7d07b2870b00: fa fa fa fa fa fa fa fa fd fd[fd]fd fd fd fd fd
==1359001==ABORTING
TXT;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/repro-viewer-' . getmypid() . '-' . mt_rand();
    }

    protected function tearDown(): void
    {
        Fs::removeTree($this->root);
    }

    public function testAsanReportNamesTheDefectSiteAndIgnoresAddresses(): void
    {
        $lines = explode("\n", self::UAF);
        $analysis = SanitizerAnalysis::parse([...$lines, ...str_replace(['1359001', '0x7d07'], ['1359012', '0x7c3e'], $lines)]);

        self::assertNotNull($analysis);
        self::assertSame('heap-use-after-free', $analysis->type);
        self::assertSame('READ of size 8', $analysis->access);
        self::assertSame(2, $analysis->reports);
        self::assertSame(
            'heap-use-after-free READ of size 8 in relayTryGetLong (commands.c:2936) ← relayRawHRandFieldRCmd | freed in execute_ex (php8.5)',
            $analysis->headline(),
        );
        self::assertSame(
            'AddressSanitizer: heap-use-after-free relayTryGetLong@commands.c:2936 < relayRawHRandFieldRCmd < relayRawCommand',
            $analysis->signature(),
        );
        self::assertStringStartsWith('SUMMARY:', $analysis->excerpt[count($analysis->excerpt) - 1]);
    }

    public function testDeadlySignalLeakAndUbsanReports(): void
    {
        $segv = SanitizerAnalysis::parse([
            'AddressSanitizer:DEADLYSIGNAL',
            '==7==ERROR: AddressSanitizer: SEGV on unknown address 0x000000000010 (pc 0x7f bp 0x1 sp 0x2 T0)',
            '==7==The signal is caused by a READ memory access.',
            '    #0 0x7f00 in relayReplyToZVal /src/relay/src/reply.c:88:5',
        ]);
        self::assertNotNull($segv);
        self::assertSame('SEGV READ in relayReplyToZVal (reply.c:88)', $segv->headline());

        $leak = SanitizerAnalysis::parse([
            '==9==ERROR: LeakSanitizer: detected memory leaks',
            'Direct leak of 32 byte(s) in 1 object(s) allocated from:',
            '    #0 0x7f in malloc ../../../../src/libsanitizer/asan/asan_malloc_linux.cpp:67',
            '    #1 0x7f in redis_sock_create /src/redis/library.c:12',
            'Indirect leak of 8 byte(s) in 1 object(s) allocated from:',
            '    #0 0x7f in other_site /src/redis/other.c:1',
        ]);
        self::assertNotNull($leak);
        self::assertSame('detected memory leaks Direct leak of 32 bytes in 1 objects in redis_sock_create (library.c:12)', $leak->headline());

        $ub = SanitizerAnalysis::parse(['/src/redis/redis.c:123:45: runtime error: signed integer overflow: 0x7fff + 1']);
        self::assertNotNull($ub);
        self::assertSame('UBSan signed integer overflow: 0x… + 1 at redis.c:123', $ub->headline());

        self::assertNull(SanitizerAnalysis::parse(['PHP Warning: nothing to see', '']));
    }

    public function testGdbFramesSkipSignalPlumbing(): void
    {
        $frames = array_values(array_filter(array_map(Backtrace::parseGdbFrame(...), [
            '#0  __pthread_kill_implementation (threadid=1, signo=6) at ./nptl/pthread_kill.c:44',
            '#1  0x00007f0 in raise (sig=6) at ../sysdeps/posix/raise.c:26',
            '#2  <signal handler called>',
            '#3  0x00007f1 in relayInvalidate (key=0x0) at /src/relay/src/relay.c:1171',
            '#4  0x00007f2 in ?? () from /usr/lib/libc.so.6',
            '#5  0x00007f3 in relayRead (r=0x1) at /src/relay/src/io.c:9',
        ])));

        self::assertCount(5, $frames);
        self::assertSame('relayInvalidate (relay.c:1171) ← ?? ← relayRead', (new Backtrace($frames))->site(2));
    }

    public function testScanGroupsListsAndShowsCapturesWithoutWriting(): void
    {
        $this->capture('sanitizer/10.00001', ['kind' => 'sanitizer', 'steps' => 5000, 'captured_at' => '2026-10-23T02:14:47+00:00'], self::UAF, 897);
        $this->capture('sanitizer/10.00002', ['kind' => 'sanitizer', 'steps' => 5000, 'captured_at' => '2026-10-23T02:15:00+00:00'], str_replace('1359001', '42', self::UAF));
        $this->capture('crash/10.00003', [
            'kind' => 'crash', 'signal' => 6, 'signal_name' => 'SIGABRT', 'steps' => 40, 'captured_at' => '2026-10-23T02:16:00+00:00',
        ], "php: /src/relay/src/relay.c:1171: relayInvalidate: Assertion `Z_TYPE_P(key) == IS_STRING' failed.\n");
        $this->capture('failed/10.00004', ['kind' => 'timeout', 'run_timeout_seconds' => 60, 'kill_signal_name' => 'SIGKILL', 'captured_at' => '2026-10-23T02:17:00+00:00', 'capture_failure' => 'rr trace incomplete'], '');
        $before = $this->snapshot();

        $repros = Reproducer::scan($this->root);
        self::assertSame(['failed/10.00004', 'crash/10.00003', 'sanitizer/10.00002', 'sanitizer/10.00001'], array_map(static fn (Reproducer $r): string => $r->id, $repros));
        self::assertSame('897←5000', $repros[3]->steps());
        self::assertSame($repros[2]->signature(), $repros[3]->signature());
        self::assertSame("SIGABRT assertion `Z_TYPE_P(key) == IS_STRING` failed in relayInvalidate (relay.c:1171)", $repros[1]->headline());
        self::assertFalse($repros[0]->replayable());
        self::assertSame('timed out after 60s (SIGKILL)', $repros[0]->headline());

        $list = $this->runApp([$this->root, '--group']);
        self::assertStringContainsString('2×  897←5000', $list);
        self::assertStringContainsString('4 captures, 3 distinct signatures', $list);

        $show = $this->runApp([$this->root, '--show', 'sanitizer/10.00001', '--no-gdb']);
        self::assertStringContainsString('== Sanitizer report', $show);
        self::assertStringContainsString('minimized: the same failure at 897 steps', $show);
        self::assertStringContainsString('freed by:', $show);
        self::assertSame($before, $this->snapshot(), 'the viewer must never write into a reproducer directory');
    }

    /** @param array<string, mixed> $meta */
    private function capture(string $id, array $meta, string $stderr, ?int $minimized = null): string
    {
        $dir = $this->root . '/' . $id;
        Fs::ensureDir($dir);
        file_put_contents($dir . '/meta.json', json_encode($meta));
        file_put_contents($dir . '/stderr.log', $stderr);
        file_put_contents($dir . '/stdout.log', '');
        file_put_contents($dir . '/command.txt', "php bin/phpredis-fuzz --steps 5000\n\n# cwd:   /work\n");
        if ($minimized !== null) {
            Fs::ensureDir($dir . '/minimized');
            file_put_contents($dir . '/minimized/meta.json', json_encode(['steps' => $minimized, 'kind' => $meta['kind']]));
        }

        return $dir;
    }

    private function snapshot(): string
    {
        return (string) shell_exec('find ' . escapeshellarg($this->root) . ' -exec ls -ld --time-style=full-iso {} + -type f -exec md5sum {} + | sort');
    }

    /** @param list<string> $arguments */
    private function runApp(array $arguments): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $status = (new ReproViewerApplication($stream))->run($arguments);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        self::assertSame(0, $status, $output);

        return $output;
    }
}
