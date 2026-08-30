<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\get;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvocationModeTest extends TestCase
{
    public function testStrictInvocationRejectsCoercibleInternalArguments(): void
    {
        $command = new get();
        $command->setClientInvoker(InvocationMode::Strict->invoker());

        $this->expectException(\TypeError::class);
        $command->cmd(
            new \Redis(),
            'setOption',
            (string) \Redis::OPT_SERIALIZER,
            \Redis::SERIALIZER_NONE,
        );
    }

    public function testCoerciveInvocationConvertsInternalArguments(): void
    {
        $command = new get();
        $command->setClientInvoker(InvocationMode::Coercive->invoker());

        self::assertTrue($command->cmd(
            new \Redis(),
            'setOption',
            (string) \Redis::OPT_SERIALIZER,
            \Redis::SERIALIZER_NONE,
        ));
    }

    /** @return iterable<string, array{InvocationMode}> */
    public static function invocationModes(): iterable
    {
        yield 'strict' => [InvocationMode::Strict];
        yield 'coercive' => [InvocationMode::Coercive];
    }

    #[DataProvider('invocationModes')]
    public function testInvocationPreservesReferenceArguments(InvocationMode $mode): void
    {
        $client = new class extends \Redis {
            /**
             * @param-out int $iterator
             * @return list<string>
             */
            public function scan(
                int|string|null &$iterator,
                ?string $pattern = null,
                int $count = 0,
                ?string $type = null,
            ): array {
                $iterator = 42;

                return ['value'];
            }
        };
        $cursor = null;
        $arguments = [&$cursor, null, 1, null];

        self::assertSame(
            ['value'],
            $mode->invoker()->invoke($client, 'scan', $arguments),
        );
        self::assertSame(42, $cursor);
    }

    #[DataProvider('invocationModes')]
    public function testReproductionUsesTheSelectedTypingMode(InvocationMode $mode): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpredis-fuzz-mode');
        self::assertIsString($path);

        try {
            ScriptLogger::init($path, invocationMode: $mode);
        } finally {
            ScriptLogger::finish();
        }

        $script = file_get_contents($path);
        unlink($path);
        self::assertIsString($script);
        self::assertStringContainsString(
            'declare(strict_types=' . ($mode === InvocationMode::Strict ? '1' : '0') . ');',
            $script,
        );
    }
}
