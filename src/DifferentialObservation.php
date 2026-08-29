<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final readonly class DifferentialObservation implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $reply
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     * @param array{class: class-string<\Throwable>, message: string, code: int}|null $exception
     */
    private function __construct(
        public bool $returned,
        public ?string $replyType,
        public ?array $reply,
        public array $redisErrors,
        public array $warnings,
        public ?array $exception,
        public float $durationSeconds,
        private mixed $comparisonReply,
    ) {
    }

    /**
     * @param list<string> $redisErrors
     * @param array<string, int> $warnings
     */
    public static function fromCall(
        bool $returned,
        mixed $reply,
        array $redisErrors,
        array $warnings,
        ?\Throwable $exception,
        float $durationSeconds,
    ): self {
        return new self(
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
            comparisonReply: $reply,
        );
    }

    /** @return list<string> */
    public function differences(self $other): array
    {
        $differences = [];
        if ($this->returned !== $other->returned) {
            $differences[] = 'return-behavior';
        } elseif ($this->returned && $this->comparisonReply !== $other->comparisonReply) {
            $differences[] = 'reply';
        }
        if ($this->normalizedRedisErrors() !== $other->normalizedRedisErrors()) {
            $differences[] = 'redis-errors';
        }
        if ($this->exceptionSignature() !== $other->exceptionSignature()) {
            $differences[] = 'exception';
        }

        return $differences;
    }

    /** @return array<string, int> */
    private function normalizedRedisErrors(): array
    {
        $errors = [];
        foreach ($this->redisErrors as $error) {
            $errors[$error] = ($errors[$error] ?? 0) + 1;
        }
        ksort($errors);

        return $errors;
    }

    private function exceptionSignature(): ?string
    {
        if ($this->exception === null) {
            return null;
        }

        /* PhpRedis and Relay necessarily use different exception classes, and
         * Relay appends an internal source location to Redis exceptions. Do
         * not apply aggregate diagnostic normalization here: identical calls
         * must still detect a wrong key, script digest, or other value. */
        $message = preg_replace(
            '~ \(RELAY_ERR_[A-Z_]+; [^)]+:\d+\)$~',
            '',
            $this->exception['message'],
        );
        if ($message === null) {
            throw new \LogicException('Invalid differential exception pattern');
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'returned' => $this->returned,
            'reply_type' => $this->replyType,
            'reply' => $this->reply,
            'redis_errors' => $this->redisErrors,
            'warnings' => $this->warnings,
            'exception' => $this->exception,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
