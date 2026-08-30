<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

final readonly class StatefulOutcome implements \JsonSerializable
{
    /**
     * @param list<StatefulObservation> $steps
     * @param list<array{name: string, passed: bool, expected: mixed, observed: mixed}> $postconditions
     */
    public function __construct(
        public string $scenario,
        public string $status,
        public string $clientId,
        public array $steps,
        public array $postconditions,
        public ?string $failure,
    ) {
    }

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scenario' => $this->scenario,
            'status' => $this->status,
            'client_id' => $this->clientId,
            'steps' => $this->steps,
            'postconditions' => $this->postconditions,
            'failure' => $this->failure,
        ];
    }
}
