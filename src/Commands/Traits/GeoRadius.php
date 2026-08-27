<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Traits;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

trait GeoRadius {
    private const SIMPLE_OPTIONS = ['WITHCOORD' =>1, 'WITHDIST' => 1, 'WITHHASH'  => 1];
    private const SORT_OPTIONS   = ['ASC' => 1, 'DESC' => 1];
    private const STORE_OPTIONS  = ['STORE' => 1, 'STOREDIST' => 1];

    /* Units with associated multipliers */
    private const UNITS = [
        'm'  => 1,
        'km' => .001,
        'ft' => 3.28084,
        'mi' => 0.000621371,
    ];

    /* Radius of the earth in meters */
    private const EARTH_RADIUS = 6371000;

    private static function randomDistance(string $unit): float {
        return rand(1, self::EARTH_RADIUS) * self::UNITS[$unit];
    }

    public static function randomUnit(): string {
        return array_rand(self::UNITS);
    }

    /**
     * @return array{0: float, 1: float}|float
     */
    public static function randomShape(string $unit): array|float {
        if (rand() & 1) {
            return [self::randomDistance($unit), self::randomDistance($unit)];
        } else {
            return self::randomDistance($unit);
        }
    }

    /**
     * @return array<mixed>|null
     */
    public static function randomOptions(FuzzConfig $config, bool $write): ?array {
        $options = null;

        $rng = rand();

        $nsimple = $rng % count(self::SIMPLE_OPTIONS);
        if ($nsimple) {
            $o = array_rand(self::SIMPLE_OPTIONS, $nsimple);
            foreach (is_array($o) ? $o : [$o] as $v)
                $options[] = $v;
        }

        if ($rng & 0x4)
            $options[] = array_rand(self::SORT_OPTIONS);

        if (! $nsimple && $write && ($rng & 0x8)) {
            $type = array_rand(self::STORE_OPTIONS);
            $options[$type] = $config->getRandomKey(Command::GEO);
        }

        if ($rng & 0x10)
            $options['COUNT'] = rand(1, $config->getMembers());
        else if ($rng & 0x20)
            $options['COUNT'] = [rand(1, $config->getMembers()), $rng & 0x40];

        return $options;
    }
}
