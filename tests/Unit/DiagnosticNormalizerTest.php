<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\DiagnosticNormalizer;
use PHPUnit\Framework\TestCase;

final class DiagnosticNormalizerTest extends TestCase
{
    public function testGeneratedNoGroupKeysShareOneFingerprint(): void
    {
        $first = "NOGROUP No such key 'stream:{7}:55' or consumer group 'fuzzer'";
        $second = "NOGROUP No such key 'stream:{3}:48' or consumer group 'fuzzer'";

        self::assertSame(
            "NOGROUP No such key '<key>' or consumer group 'fuzzer'",
            DiagnosticNormalizer::normalize($first),
        );
        self::assertSame(
            DiagnosticNormalizer::normalize($first),
            DiagnosticNormalizer::normalize($second),
        );
        self::assertSame(
            ["NOGROUP No such key '<key>' or consumer group 'fuzzer'" => 5],
            DiagnosticNormalizer::aggregate([$first => 2, $second => 3]),
        );
    }

    public function testNoGroupExceptionsKeepTheirClassAndExactConsumerGroup(): void
    {
        $diagnostic = "Relay\\Exception: NOGROUP No such key 'stream:{8}:72' "
            . "or consumer group 'workers' (RELAY_ERR_REDIS; commands.c:17954)";

        self::assertSame(
            "Relay\\Exception: NOGROUP No such key '<key>' or consumer group 'workers' "
                . '(RELAY_ERR_REDIS; commands.c:<line>)',
            DiagnosticNormalizer::normalize($diagnostic),
        );
    }

    public function testLuaAndSourceLocationsAreStableAcrossBuilds(): void
    {
        self::assertSame(
            'ERR failure script: <sha>, on @user_script:<line>.',
            DiagnosticNormalizer::normalize(
                'ERR failure script: 564ede6e7b34318eec9ab85ec305944d1fd6ce46, '
                    . 'on @user_script:17.',
            ),
        );
        self::assertSame(
            'PHP Warning: Relay\\Cluster::getOption(): failed in Command.php on line <line>',
            DiagnosticNormalizer::normalize(
                'PHP Warning: Relay\\Cluster::getOption(): failed in '
                    . '/workspace/src/Commands/Command.php on line 376',
            ),
        );
    }

    public function testSemanticDiagnosticValuesAreNotGenericallyReplaced(): void
    {
        self::assertSame(
            'ERR DB index is out of range',
            DiagnosticNormalizer::normalize('ERR DB index is out of range'),
        );
        self::assertNotSame(
            DiagnosticNormalizer::normalize(
                "NOGROUP No such key 'stream:{1}:1' or consumer group 'first'",
            ),
            DiagnosticNormalizer::normalize(
                "NOGROUP No such key 'stream:{1}:2' or consumer group 'second'",
            ),
        );
    }
}
