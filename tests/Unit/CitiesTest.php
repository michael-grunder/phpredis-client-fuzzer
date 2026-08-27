<?php

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Data\Cities;
use PHPUnit\Framework\TestCase;

final class CitiesTest extends TestCase {
    public function testCityFixtureLoads(): void {
        self::assertInstanceOf(Cities::class, Cities::instance());
    }
}
