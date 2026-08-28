<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\ProblematicCommandDetector;
use PHPUnit\Framework\TestCase;

final class ProblematicCommandDetectorTest extends TestCase
{
    public function testReportsOnlyFalseRepliesForACommandTheServerSupports(): void
    {
        $results = $this->results([
            'get' => ['false' => 3],
            'set' => ['false' => 1, 'true' => 2],
        ]);

        self::assertSame(
            ['get' => ['executions' => 3, 'false_replies' => 3]],
            (new ProblematicCommandDetector())->detect(
                $results,
                ['get' => [10 => true], 'set' => [10 => true]],
                [10 => ServerCommands::fromNames(['get', 'set'])],
                new Registry(),
            ),
        );
    }

    public function testExcludesCommandsMissingFromAnyServerThatReturnedFalse(): void
    {
        $results = $this->results(['delex' => ['false' => 2]]);

        self::assertSame([], (new ProblematicCommandDetector())->detect(
            $results,
            ['delex' => [10 => true, 20 => true]],
            [
                10 => ServerCommands::fromNames(['delex']),
                20 => ServerCommands::fromNames(['get']),
            ],
            new Registry(),
        ));
    }

    public function testExcludesCommandsWhenServerMetadataIsUnavailable(): void
    {
        $results = $this->results(['get' => ['false' => 1]]);

        self::assertSame([], (new ProblematicCommandDetector())->detect(
            $results,
            ['get' => [10 => true]],
            [10 => null],
            new Registry(),
        ));
    }

    public function testExcludesCursorBasedScanCommands(): void
    {
        $results = $this->results([
            'scan' => ['false' => 2],
            'hscan' => ['false' => 2],
            'sscan' => ['false' => 2],
            'zscan' => ['false' => 2],
        ]);
        $clients = array_fill_keys(array_keys($results), [10 => true]);

        self::assertSame([], (new ProblematicCommandDetector())->detect(
            $results,
            $clients,
            [10 => ServerCommands::fromNames(array_keys($results))],
            new Registry(),
        ));
    }

    public function testResolvesCommandsByTheirExposedNames(): void
    {
        $results = $this->results(['echo' => ['false' => 1]]);

        self::assertSame(
            ['echo' => ['executions' => 1, 'false_replies' => 1]],
            (new ProblematicCommandDetector())->detect(
                $results,
                ['echo' => [10 => true]],
                [10 => ServerCommands::fromNames(['echo'])],
                new Registry(),
            ),
        );
    }

    /**
     * @param array<string, array<string, int>> $replies
     * @return array<string, array{count: int, replies: array<string, int>, exceptions: array<string, int>}>
     */
    private function results(array $replies): array
    {
        $results = [];
        foreach ($replies as $name => $counts) {
            $results[$name] = [
                'count' => array_sum($counts),
                'replies' => $counts,
                'exceptions' => [],
            ];
        }

        return $results;
    }
}
