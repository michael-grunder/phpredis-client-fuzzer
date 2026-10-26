<?php

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Data\Cities;
use PHPUnit\Framework\TestCase;

final class CitiesTest extends TestCase {
    public function testCityFixtureLoads(): void {
        self::assertInstanceOf(Cities::class, Cities::instance());
    }
}
