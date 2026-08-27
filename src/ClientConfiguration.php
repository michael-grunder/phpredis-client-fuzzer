<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final readonly class ClientConfiguration
{
    /**
     * @param list<string> $seeds Cluster seeds in host:port form.
     * @param string|array{0: string, 1: string}|null $auth
     */
    public function __construct(
        public ClientType $type = ClientType::Redis,
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public array $seeds = ['127.0.0.1:6379'],
        public float $timeout = 1.0,
        public float $readTimeout = 1.0,
        public string|array|null $auth = null,
        public string $prefix = '',
        public string $serializer = 'none',
        public string $compression = 'none',
        public bool $relayCompatibility = true,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535');
        }
        if ($seeds === []) {
            throw new \InvalidArgumentException('At least one cluster seed is required');
        }
        if ($timeout < 0 || $readTimeout < 0) {
            throw new \InvalidArgumentException('Timeouts cannot be negative');
        }
    }
}
