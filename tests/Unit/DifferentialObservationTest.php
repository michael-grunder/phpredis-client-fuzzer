<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\DifferentialObservation;
use PHPUnit\Framework\TestCase;

final class DifferentialObservationTest extends TestCase
{
    public function testRedisErrorValuesRemainExact(): void
    {
        $reference = $this->observation(
            reply: false,
            redisErrors: ["NOGROUP No such key 'stream:{7}:55' or consumer group 'fuzzer'"],
        );
        $subject = $this->observation(
            reply: false,
            redisErrors: ["NOGROUP No such key 'stream:{2}:19' or consumer group 'fuzzer'"],
        );

        self::assertSame(['redis-errors'], $reference->differences($subject));
    }

    public function testReplyAndRedisErrorDifferencesRemainSeparate(): void
    {
        $reference = $this->observation(reply: 1, redisErrors: ['ERR one']);
        $subject = $this->observation(reply: '1', redisErrors: ['ERR two']);

        self::assertSame(['reply', 'redis-errors'], $reference->differences($subject));
    }

    public function testExceptionClassIsIgnoredButNormalizedMessageIsCompared(): void
    {
        $reference = $this->observation(
            returned: false,
            exception: new \RuntimeException('ERR failure'),
        );
        $equivalent = $this->observation(
            returned: false,
            exception: new \LogicException(
                'ERR failure (RELAY_ERR_REDIS; commands.c:456)',
            ),
        );
        $different = $this->observation(
            returned: false,
            exception: new \LogicException('ERR another failure'),
        );

        self::assertSame([], $reference->differences($equivalent));
        self::assertSame(['exception'], $reference->differences($different));
    }

    public function testReturnBehaviorIsComparedIndependently(): void
    {
        $returned = $this->observation(reply: false);
        $thrown = $this->observation(
            returned: false,
            exception: new \RuntimeException('failed'),
        );

        self::assertSame(
            ['return-behavior', 'exception'],
            $returned->differences($thrown),
        );
    }

    /** @param list<string> $redisErrors */
    private function observation(
        mixed $reply = null,
        array $redisErrors = [],
        bool $returned = true,
        ?\Throwable $exception = null,
    ): DifferentialObservation {
        return DifferentialObservation::fromCall(
            returned: $returned,
            reply: $reply,
            redisErrors: $redisErrors,
            warnings: [],
            exception: $exception,
            durationSeconds: 0.001,
        );
    }
}
