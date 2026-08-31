<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\CommandCatalogApplication;
use PHPUnit\Framework\TestCase;

final class CommandCatalogApplicationTest extends TestCase
{
    public function testListsCatalogPropertiesWithoutAFilter(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new CommandCatalogApplication($output, $error))->run([]);
        $contents = self::contents($output);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^COMMAND\s+TYPE\s+CATEGORIES\s+SURFACES$/m', $contents);
        self::assertMatchesRegularExpression('/^get\s+string\s+read,cached\s+client,raw$/m', $contents);
        self::assertMatchesRegularExpression(
            '/^flushall\s+none\s+write,flush,invalidating\s+client,raw$/m',
            $contents,
        );
        self::assertMatchesRegularExpression('/\n\d+ commands\n$/', $contents);
        self::assertSame('', self::contents($error));
    }

    public function testCommandsUsesFuzzerGlobAndExclusionSemantics(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new CommandCatalogApplication($output, $error))->run([
            '--commands=get*,-getex',
        ]);
        $contents = self::contents($output);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^get\s+/m', $contents);
        self::assertDoesNotMatchRegularExpression('/^getex\s+/m', $contents);
        self::assertDoesNotMatchRegularExpression('/^set\s+/m', $contents);
        self::assertSame('', self::contents($error));
    }

    public function testFlagFilterCanInspectSafetyCategories(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new CommandCatalogApplication($output, $error))->run([
            '--commands=@blocking',
        ]);
        $contents = self::contents($output);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^blpop\s+list\s+[^\n]*blocking[^\n]*$/m', $contents);
        self::assertDoesNotMatchRegularExpression('/^get\s+/m', $contents);
        self::assertSame('', self::contents($error));
    }

    public function testInvalidFilterIsReported(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new CommandCatalogApplication($output, $error))->run([
            '--commands=@unknown',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', self::contents($output));
        self::assertStringContainsString('Unknown command flag: @unknown', self::contents($error));
    }

    public function testHelpDoesNotInspectRedis(): void
    {
        $output = self::stream();
        $error = self::stream();

        $status = (new CommandCatalogApplication($output, $error))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('phpredis-commands', self::contents($output));
        self::assertSame('', self::contents($error));
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
