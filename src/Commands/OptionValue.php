<?php

namespace Mgrunder\RedisClientTester\Commands;

use Mgrunder\RedisClientTester\ScriptArg;
use Mgrunder\RedisClientTester\OptionChoices;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class OptionValue extends ScriptArg {
    public function __construct(private mixed $option, private mixed $value) {}

    /** @param list<string> $constants */
    private function relayClusterValue(string $option, array $constants): ?string {
        if (!defined($option) || constant($option) !== $this->option) {
            return null;
        }

        foreach ($constants as $constant) {
            if (defined($constant) && constant($constant) === $this->value) {
                return '\\' . $constant;
            }
        }

        return var_export($this->value, true);
    }

    private function relayClusterOptionValue(): ?string {
        $options = [
            'Relay\\Cluster::OPT_DISTRIBUTE' => [
                'Relay\\Cluster::DISTRIBUTE_NONE',
                'Relay\\Cluster::DISTRIBUTE_RANDOM',
                'Relay\\Cluster::DISTRIBUTE_RANDOM_REPLICA',
                'Relay\\Cluster::DISTRIBUTE_REPLICAS',
                'Relay\\Cluster::DISTRIBUTE_ALL',
            ],
            'Relay\\Cluster::OPT_FAILOVER' => [
                'Relay\\Cluster::FAILOVER_NONE',
                'Relay\\Cluster::FAILOVER_PRIMARY',
                'Relay\\Cluster::FAILOVER_RANDOM_REPLICA',
                'Relay\\Cluster::FAILOVER_REPLICAS',
                'Relay\\Cluster::FAILOVER_ALL',
            ],
            'Relay\\Cluster::OPT_MULTIKEY_REORDERING' => [
                'Relay\\Cluster::MULTIKEY_REORDER_NONE',
                'Relay\\Cluster::MULTIKEY_REORDER_READS',
                'Relay\\Cluster::MULTIKEY_REORDER_WRITES',
                'Relay\\Cluster::MULTIKEY_REORDER_ALL',
            ],
            'Relay\\Cluster::OPT_NODE_READ_TIMEOUT' => [],
            'Relay\\Cluster::OPT_AVAILABILITY_ZONE' => [],
        ];

        foreach ($options as $option => $constants) {
            $code = $this->relayClusterValue($option, $constants);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    private function compressionValue(): string {
        return $this->codecValue(OptionChoices::COMPRESSION);
    }

    private function serializerValue(): string {
        return $this->codecValue(OptionChoices::SERIALIZER);
    }

    /** @param array<string, string> $choices */
    private function codecValue(array $choices): string {
        foreach ([...array_values($choices), ...array_values(OptionChoices::forClient($choices, Relay::class))] as $constant) {
            if (defined($constant) && constant($constant) === $this->value) {
                return str_starts_with($constant, 'Relay\\') ? '\\' . $constant : $constant;
            }
        }
        return var_export($this->value, true);
    }

    private function scanValue(): string {
        return match($this->value) {
            Redis::SCAN_NORETRY => 'Redis::SCAN_NORETRY',
            Redis::SCAN_RETRY => 'Redis::SCAN_RETRY',
            default => var_export($this->value, true),
        };
    }

    private function failoverValue(): string {
        return match($this->value) {
            RedisCluster::FAILOVER_NONE => 'RedisCluster::FAILOVER_NONE',
            RedisCluster::FAILOVER_ERROR => 'RedisCluster::FAILOVER_ERROR',
            RedisCluster::FAILOVER_DISTRIBUTE => 'RedisCluster::FAILOVER_DISTRIBUTE',
            RedisCluster::FAILOVER_DISTRIBUTE_SLAVES => 'RedisCluster::FAILOVER_DISTRIBUTE_SLAVES',
            default => var_export($this->value, true),
        };
    }

    private function backoffValue(): string {
        return match($this->value) {
            Redis::BACKOFF_ALGORITHM_DEFAULT => 'Redis::BACKOFF_ALGORITHM_DEFAULT',
            Redis::BACKOFF_ALGORITHM_CONSTANT => 'Redis::BACKOFF_ALGORITHM_CONSTANT',
            Redis::BACKOFF_ALGORITHM_UNIFORM => 'Redis::BACKOFF_ALGORITHM_UNIFORM',
            Redis::BACKOFF_ALGORITHM_EXPONENTIAL => 'Redis::BACKOFF_ALGORITHM_EXPONENTIAL',
            Redis::BACKOFF_ALGORITHM_FULL_JITTER => 'Redis::BACKOFF_ALGORITHM_FULL_JITTER',
            Redis::BACKOFF_ALGORITHM_EQUAL_JITTER => 'Redis::BACKOFF_ALGORITHM_EQUAL_JITTER',
            Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER => 'Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER',
            default => var_export($this->value, true),
        };
    }

    public function code(): string {
        $relayClusterValue = $this->relayClusterOptionValue();
        if ($relayClusterValue !== null) {
            return $relayClusterValue;
        }

        return match($this->option) {
            Redis::OPT_SERIALIZER  => $this->serializerValue(),
            Redis::OPT_COMPRESSION => $this->compressionValue(),
            Redis::OPT_SCAN => $this->scanValue(),
            Redis::OPT_BACKOFF_ALGORITHM => $this->backoffValue(),
            RedisCluster::OPT_SLAVE_FAILOVER => $this->failoverValue(),
            default => var_export($this->value, true),
        };
    }

    public function value(): mixed {
        return $this->value;
    }
}
