<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

final readonly class TlsConfiguration
{
    /** @var array<string, mixed> */
    public array $options;

    /** @param array<mixed> $options PHP SSL context options, passed through to the client. */
    public function __construct(public bool $enabled = false, array $options = [])
    {
        if (!$enabled && $options !== []) {
            throw new \InvalidArgumentException('TLS options require TLS to be enabled');
        }
        $validated = [];
        foreach ($options as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('TLS options must have nonempty string keys');
            }
            $validated[$name] = $value;
        }
        $this->options = $validated;
    }

    public function endpoint(string $host): string
    {
        if (!$this->enabled || str_starts_with($host, 'tls://')) {
            return $host;
        }
        if (str_starts_with($host, 'tcp://')) {
            $host = substr($host, 6);
        }
        if ($host === '' || str_contains($host, '://') || str_starts_with($host, '/')) {
            throw new \InvalidArgumentException('TLS requires a TCP host or cluster seed');
        }

        return 'tls://' . $host;
    }

    /** @return array{stream?: array<string, mixed>} */
    public function context(): array
    {
        return $this->options === [] ? [] : ['stream' => $this->options];
    }
}
