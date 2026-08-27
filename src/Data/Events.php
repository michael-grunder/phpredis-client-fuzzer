<?php

namespace Mgrunder\PhpredisCommandFuzzer\Data;


/** A singleton pattern class to proivde time based data to our fuzzer.
 * The data doesn't really matter, it's just all indexed in a way redis
 * streams can use it */
class Events {
    private const BUCKETS = 1024;
    private const MAX_SEQ = 256;

    private const DATA_FILES = [
        __DIR__ . '/../../data/earthquakes.json',
        __DIR__ . '/../../data/shows.json',
    ];

    /**
     * @var array<int, array{0: int, 1: int}> $buckets
     */
    private array $buckets;

    private int $offset;
    private int $maximum;

    /**
     * @var array<int, array<string, mixed>> $events
     */
    private array $events = [];

    private static ?self $instance = null;

    private function loadFile(string $file): void {
        $data = file_get_contents($file);
        if ($data === false || $data === '')
            throw new \Exception("Failed to load data file '$file'");

        $json = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($json) || $json === [])
            throw new \Exception("Failed to decode JSON data from file '$file'");

        foreach ($json as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Malformed event fixture in '$file'");
            }
            $event = [];
            foreach ($row as $field => $value) {
                if (!is_string($field)) {
                    throw new \UnexpectedValueException("Non-string event field in '$file'");
                }
                $event[$field] = $value;
            }
            $this->events[] = $event;
        }
    }

    protected function __construct() {
        $this->offset  = 1_704_067_200;
        $this->maximum = $this->offset;

        $this->buckets = array_fill(0, self::BUCKETS, [$this->offset, 0]);

        foreach (self::DATA_FILES as $file) {
            $this->loadFile($file);
        }
    }

    public static function instance(): self {
        if (self::$instance === null)
            self::$instance = new self();

        return self::$instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function randomEvent(): array {
        return $this->events[array_rand($this->events)];
    }

    public function minimumId(): int {
        return $this->offset;
    }

    public function maximumId(): int {
        return $this->maximum;
    }

    public function maximumSeq(): int {
        return self::MAX_SEQ;
    }

    public function maxSequence(): int {
        return self::MAX_SEQ;
    }

    /**
     * @return string[]
     */
    public function previousIds(string $key, int $count): array {
        $result = [];

        [$off, $seq] = $this->buckets[$this->getBucket($key)];

        while (count($result) < $count) {

            $result[] = $off . '-' . $seq;
            if ($seq-- == 0) {
                $seq = self::MAX_SEQ - 1;
                $off--;
            }
        }

        return $result;
    }

    private function getBucket(string $v): int {
        return hexdec(hash('xxh32', $v)) & (self::BUCKETS - 1);
    }

    public function nextId(string $key): string {
        $counts = &$this->buckets[$this->getBucket($key)];
        if ($counts[1] === self::MAX_SEQ) {
            $counts[0]++;
            $counts[1] = 0;
        }

        if ($counts[0] > $this->maximum)
            $this->maximum = $counts[0];

        return $counts[0] . '-' . $counts[1]++;

    }

    public function randomReadId(): string {
        return match(rand(0, 2)) {
            0 => '>',
            1 => $this->randomId(),
            2 => '$',
        };
    }

    public function randomId(): string {
        return rand($this->minimumId(), $this->maximumId()) . '-' .
               rand(0, Events::instance()->maximumSeq());
    }

    /**
     * Return an array with a randomized stream id range.  This idiom is used
     * in many Redis STREAM commands.
     *
     * @return array{0: string, 1: string}
     */
    public function randomIdRange(): array {
        return match (rand() % 4) {
            0 => ['-', '+'],
            1 => ['-', $this->randomId()],
            2 => [$this->randomId(), '+'],
            3 => [$this->randomId(), $this->randomId()],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function randomEvents(int $count): array {
        $result = [];

        assert($count > 0);

        $ids = array_rand($this->events, $count);
        if ( ! is_array($ids))
            $ids = [$ids];

        return array_intersect_key($this->events, array_flip($ids));
    }
}
