<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;
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
        ));
    }

    public function testExcludesCommandsWhenServerMetadataIsUnavailable(): void
    {
        $results = $this->results(['get' => ['false' => 1]]);

        self::assertSame([], (new ProblematicCommandDetector())->detect(
            $results,
            ['get' => [10 => true]],
            [10 => null],
        ));
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
