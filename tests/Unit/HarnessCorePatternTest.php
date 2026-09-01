<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\CorePattern;
use PHPUnit\Framework\TestCase;

final class HarnessCorePatternTest extends TestCase
{
    public function testAbsolutePatternGlobEmbedsPidAndWildcardsTheRest(): void
    {
        $pattern = new CorePattern('/var/crash/core.%e.%p.%t', false);

        self::assertTrue($pattern->isolatesByPid());
        self::assertFalse($pattern->isPiped());
        self::assertSame(
            ['/var/crash/core.*.4242.*'],
            $pattern->globsForPid(4242, '/tmp/ignored'),
        );
    }

    public function testRelativePatternResolvesAgainstFallbackDirectory(): void
    {
        $pattern = new CorePattern('core.%p', false);

        self::assertSame(['/work/run/core.4242'], $pattern->globsForPid(4242, '/work/run/'));
    }

    public function testCoreUsesPidSuffixIsAppendedWhenPatternLacksPid(): void
    {
        $pattern = new CorePattern('core', true);

        self::assertFalse($pattern->isolatesByPid());
        self::assertSame(['/work/core.4242'], $pattern->globsForPid(4242, '/work'));
    }

    public function testPipedPatternHasNoGlobs(): void
    {
        $pattern = new CorePattern('|/usr/lib/systemd/systemd-coredump %P %u %g', false);

        self::assertTrue($pattern->isPiped());
        self::assertSame([], $pattern->globsForPid(4242, '/tmp'));
    }
}
