<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\ScriptArg;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class OptionValue extends ScriptArg {
    public function __construct(private mixed $option, private mixed $value) {}

    private function compressionValue(): string {
        return match ($this->value) {
            Redis::COMPRESSION_NONE => 'Redis::COMPRESSION_NONE',
            Redis::COMPRESSION_LZF => 'Redis::COMPRESSION_LZF',
            Redis::COMPRESSION_ZSTD => 'Redis::COMPRESSION_ZSTD',
            Redis::COMPRESSION_LZ4 => 'Redis::COMPRESSION_LZ4',
            default => var_export($this->value, true),
        };
    }

    private function serializerValue(): string {
        return match ($this->value) {
            Redis::SERIALIZER_NONE => 'Redis::SERIALIZER_NONE',
            Redis::SERIALIZER_PHP => 'Redis::SERIALIZER_PHP',
            Redis::SERIALIZER_IGBINARY => 'Redis::SERIALIZER_IGBINARY',
            Redis::SERIALIZER_MSGPACK => 'Redis::SERIALIZER_MSGPACK',
            Redis::SERIALIZER_JSON => 'Redis::SERIALIZER_JSON',
            default => var_export($this->value, true),
        };
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
