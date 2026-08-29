<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

/**
 * The complete observable result of one scheduled fuzz invocation.
 */
final readonly class InvocationOutcome implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $reply
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     * @param array{class: class-string<\Throwable>, message: string, code: int}|null $exception
     */
    public function __construct(
        public int $sequence,
        public string $command,
        public ?string $variant,
        public string $clientId,
        public int $clientIndex,
        public string $clientClass,
        public string $operation,
        public ?string $replyType,
        public ?array $reply,
        public array $redisErrors,
        public array $warnings,
        public ?array $exception,
        public float $durationSeconds,
        public ?int $modeBefore,
        public ?int $modeAfter,
        public string $slotPolicy,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'sequence' => $this->sequence,
            'command' => $this->command,
            'variant' => $this->variant,
            'client_id' => $this->clientId,
            'client_index' => $this->clientIndex,
            'client_class' => $this->clientClass,
            'operation' => $this->operation,
            'reply_type' => $this->replyType,
            'reply' => $this->reply,
            'redis_errors' => $this->redisErrors,
            'warnings' => $this->warnings,
            'exception' => $this->exception,
            'duration_seconds' => $this->durationSeconds,
            'mode_before' => $this->modeBefore,
            'mode_after' => $this->modeAfter,
            'slot_policy' => $this->slotPolicy,
        ];
    }
}
