<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

use Mgrunder\RedisClientTester\ValueSummary;

/**
 * One recorded client invocation made by a scenario. Redis errors, thrown
 * exceptions and ordinary replies stay separate so that postconditions can
 * distinguish a local client rejection from a server error.
 */
final readonly class ScenarioCall
{
    /** @param list<string> $redisErrors */
    public function __construct(
        public string $operation,
        public bool $returned,
        public mixed $reply,
        public array $redisErrors,
        public ?\Throwable $exception,
    ) {
    }

    public function error(): ?string
    {
        return $this->redisErrors[0] ?? null;
    }

    public function threw(): bool
    {
        return $this->exception !== null;
    }

    /** The client answered without reaching (or without reporting) the server. */
    public function rejectedLocally(): bool
    {
        return $this->returned
            && $this->reply === false
            && $this->redisErrors === []
            && $this->exception === null;
    }

    public function failed(): bool
    {
        return !$this->returned || $this->redisErrors !== [] || $this->reply === false;
    }

    /**
     * Postcondition payload describing what the client actually did.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'operation' => $this->operation,
            'returned' => $this->returned,
            'reply' => $this->returned ? ValueSummary::summarize($this->reply) : null,
            'redis_errors' => $this->redisErrors,
            'exception' => $this->exception === null ? null : [
                'class' => $this->exception::class,
                'message' => $this->exception->getMessage(),
            ],
        ];
    }
}
