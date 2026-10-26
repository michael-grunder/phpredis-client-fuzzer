<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\IncludeCategories;
use PHPUnit\Framework\TestCase;

final class IncludeCategoriesTest extends TestCase
{
    public function testOmittedValueIncludesNothing(): void
    {
        $includes = IncludeCategories::parse(null);

        self::assertFalse($includes->admin);
        self::assertFalse($includes->local);
        self::assertFalse($includes->connection);
        self::assertFalse($includes->flush);
        self::assertFalse($includes->stateful);
        self::assertFalse($includes->crash);
    }

    public function testCsvSelectsCategoriesCaseInsensitively(): void
    {
        $includes = IncludeCategories::parse(' admin,LOCAL,Connection,stateful ');

        self::assertTrue($includes->admin);
        self::assertTrue($includes->local);
        self::assertTrue($includes->connection);
        self::assertFalse($includes->flush);
        self::assertTrue($includes->stateful);
        self::assertFalse($includes->crash);
    }

    public function testCrashIsSelectedOnlyWhenNamed(): void
    {
        $includes = IncludeCategories::parse('CRASH');

        self::assertTrue($includes->crash);
        self::assertFalse($includes->admin);
        self::assertFalse($includes->local);
        self::assertFalse($includes->connection);
        self::assertFalse($includes->flush);
        self::assertFalse($includes->stateful);
    }

    public function testAllSelectsEveryCategoryExceptCrash(): void
    {
        $includes = IncludeCategories::parse('all');

        self::assertTrue($includes->admin);
        self::assertTrue($includes->local);
        self::assertTrue($includes->connection);
        self::assertTrue($includes->flush);
        self::assertTrue($includes->stateful);
        self::assertFalse($includes->crash);
    }

    public function testAllCombinesWithAnExplicitCrash(): void
    {
        $includes = IncludeCategories::parse('all,crash');

        self::assertTrue($includes->admin);
        self::assertTrue($includes->crash);
    }

    public function testConnectionAndLocalAreIndependent(): void
    {
        $connection = IncludeCategories::parse('connection');
        self::assertTrue($connection->connection);
        self::assertFalse($connection->local);

        $local = IncludeCategories::parse('local');
        self::assertTrue($local->local);
        self::assertFalse($local->connection);
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown --include category: unknown');

        IncludeCategories::parse('admin,unknown');
    }

    public function testEmptyValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--include cannot be empty');

        IncludeCategories::parse('');
    }
}
