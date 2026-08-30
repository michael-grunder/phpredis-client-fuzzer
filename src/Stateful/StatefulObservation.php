<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Stateful;

use Mgrunder\PhpredisCommandFuzzer\ValueSummary;

final readonly class StatefulObservation implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $reply
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     * @param array{class: class-string<\Throwable>, message: string, code: int}|null $exception
     */
    public function __construct(
        public string $operation,
        public bool $returned,
        public ?string $replyType,
        public ?array $reply,
        public array $redisErrors,
        public array $warnings,
        public ?array $exception,
        public float $durationSeconds,
        public ?int $modeBefore,
        public ?int $modeAfter,
    ) {
    }

    /**
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     */
    public static function fromCall(
        string $operation,
        bool $returned,
        mixed $reply,
        array $redisErrors,
        array $warnings,
        ?\Throwable $exception,
        float $durationSeconds,
        ?int $modeBefore,
        ?int $modeAfter,
    ): self {
        return new self(
            operation: $operation,
            returned: $returned,
            replyType: $returned ? ValueSummary::type($reply) : null,
            reply: $returned ? ValueSummary::summarize($reply) : null,
            redisErrors: $redisErrors,
            warnings: $warnings,
            exception: $exception === null ? null : [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ],
            durationSeconds: $durationSeconds,
            modeBefore: $modeBefore,
            modeAfter: $modeAfter,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'returned' => $this->returned,
            'reply_type' => $this->replyType,
            'reply' => $this->reply,
            'redis_errors' => $this->redisErrors,
            'warnings' => $this->warnings,
            'exception' => $this->exception,
            'duration_seconds' => $this->durationSeconds,
            'mode_before' => $this->modeBefore,
            'mode_after' => $this->modeAfter,
        ];
    }
}
