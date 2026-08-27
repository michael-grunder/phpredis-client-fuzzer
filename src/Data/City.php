<?php

namespace Mgrunder\PhpredisCommandFuzzer\Data;


class City {
    private string $name;
    private float $lat;
    private float $lng;
    private string $country;
    private int $population;

    public function __construct(string $name, float $lat, float $lng, string $country, int $population) {
        $this->name = $name;
        $this->lat = $lat;
        $this->lng = $lng;
        $this->country = $country;
        $this->population = $population;
    }

    public function name(): string {
        return $this->name;
    }

    public function lat(): float {
        return $this->lat;
    }

    public function lng(): float {
        return $this->lng;
    }

    public function country(): string {
        return $this->country;
    }

    public function population(): int {
        return $this->population;
    }
}

