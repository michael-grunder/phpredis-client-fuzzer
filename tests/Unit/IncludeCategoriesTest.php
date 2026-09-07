<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\IncludeCategories;
use PHPUnit\Framework\TestCase;

final class IncludeCategoriesTest extends TestCase
{
    public function testOmittedValueIncludesNothing(): void
    {
        $includes = IncludeCategories::parse(null);

        self::assertFalse($includes->admin);
        self::assertFalse($includes->local);
        self::assertFalse($includes->flush);
        self::assertFalse($includes->stateful);
        self::assertFalse($includes->crash);
    }

    public function testCsvSelectsCategoriesCaseInsensitively(): void
    {
        $includes = IncludeCategories::parse(' admin,LOCAL,stateful ');

        self::assertTrue($includes->admin);
        self::assertTrue($includes->local);
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
        self::assertFalse($includes->flush);
        self::assertFalse($includes->stateful);
    }

    public function testAllSelectsEveryCategoryExceptCrash(): void
    {
        $includes = IncludeCategories::parse('all');

        self::assertTrue($includes->admin);
        self::assertTrue($includes->local);
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
