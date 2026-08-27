<?php

namespace Mgrunder\PhpredisCommandFuzzer\Data;


class Cities {
    // Source: https://simplemaps.com/data/world-cities
    private const DATA_FILE = __DIR__ . '/../../data/cities.json';

    private static ?self $instance = null;

    /**
     * @var City[]
     */
    private array $cities = [];

    protected function __construct() {
        $data = file_get_contents(self::DATA_FILE);
        if ($data === false) {
            throw new \RuntimeException("Failed to open required data file '" . self::DATA_FILE . "'");
        }

        $cities = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($cities)) {
            throw new \UnexpectedValueException("Expected an array in '" . self::DATA_FILE . "'");
        }

        foreach ($cities as $city) {
            if (!is_array($city)
                || !is_string($city['name'] ?? null)
                || !is_numeric($city['lat'] ?? null)
                || !is_numeric($city['lng'] ?? null)
                || !is_string($city['country'] ?? null)
                || !is_numeric($city['population'] ?? null)) {
                throw new \UnexpectedValueException('Malformed city fixture row');
            }

            $this->cities[$city['name']] = new City(
                $city['name'],
                (float) $city['lat'],
                (float) $city['lng'],
                $city['country'],
                (int) $city['population'],
            );
        }
    }

    public static function instance(): self {
        if (self::$instance === null)
            self::$instance = new self;

        return self::$instance;
    }

    public function randomCity(): City {
        return $this->cities[array_rand($this->cities)];
    }

    /**
     * @return City[]
     */
    public function randomCities(int $count): array {
        $result = [];

        assert($count > 0);

        if ($count == 1)
            return [$this->randomCity()];

        if ($count > count($this->cities))
            $count = count($this->cities);

        $cities = array_rand($this->cities, $count);

        assert(is_array($cities));

        foreach ($cities as $name) {
            $result[] = $this->cities[$name];
        }

        return $result;
    }
}
