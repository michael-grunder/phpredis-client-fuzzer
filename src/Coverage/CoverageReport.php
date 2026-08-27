<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Coverage;

use Mgrunder\PhpredisCommandFuzzer\ClientType;

/** The result of comparing a server command table against the command catalog. */
final readonly class CoverageReport implements \JsonSerializable
{
    /**
     * @param list<string> $covered Server commands the catalog exercises.
     * @param list<string> $missing Uncovered server commands the client exposes a method for.
     * @param list<string> $unsupported Uncovered server commands the client has no method for.
     * @param array<string, string> $ignored Uncovered server command => matching ignore pattern.
     * @param list<string> $clientApi Catalog commands the server table lacks but the client class provides.
     * @param list<string> $unmatched Catalog commands that neither the server table nor the client class has.
     */
    public function __construct(
        public ClientType $clientType,
        public string $clientClass,
        public int $serverCommands,
        public int $catalogCommands,
        public array $covered,
        public array $missing,
        public array $unsupported,
        public array $ignored,
        public array $clientApi,
        public array $unmatched,
    ) {
    }

    /** Server commands that count toward the ratio: everything but the ignored ones. */
    public function considered(): int
    {
        return count($this->covered) + count($this->missing) + count($this->unsupported);
    }

    /** Covered share of the considered commands, from 0 to 1. */
    public function ratio(): float
    {
        $considered = $this->considered();

        return $considered === 0 ? 1.0 : count($this->covered) / $considered;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->clientType->value,
            'client_class' => $this->clientClass,
            'server_commands' => $this->serverCommands,
            'catalog_commands' => $this->catalogCommands,
            'considered' => $this->considered(),
            'covered_count' => count($this->covered),
            'ratio' => round($this->ratio(), 4),
            'covered' => $this->covered,
            'missing' => $this->missing,
            'unsupported' => $this->unsupported,
            'ignored' => $this->ignored,
            'client_api' => $this->clientApi,
            'unmatched' => $this->unmatched,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
