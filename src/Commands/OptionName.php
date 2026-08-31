<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\ScriptArg;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class OptionName extends ScriptArg {
    private const OPTIONS = [
        'Redis::OPT_PREFIX',
        'Redis::OPT_SERIALIZER',
        'Redis::OPT_COMPRESSION',
        'Redis::OPT_COMPRESSION_LEVEL',
        'Redis::OPT_READ_TIMEOUT',
        'Redis::OPT_SCAN',
        'Redis::OPT_TCP_KEEPALIVE',
        'Redis::OPT_MAX_RETRIES',
        'Redis::OPT_BACKOFF_ALGORITHM',
        'Redis::OPT_BACKOFF_BASE',
        'Redis::OPT_BACKOFF_CAP',
        'Redis::OPT_NULL_MULTIBULK_AS_NULL',
        'Redis::OPT_REPLY_LITERAL',
        'RedisCluster::OPT_SLAVE_FAILOVER',
        'Relay\\Relay::OPT_NULL_MULTIBULK_AS_NULL',
        'Relay\\Relay::OPT_REPLY_LITERAL',
        'Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY',
        'Relay\\Relay::OPT_THROW_ON_ERROR',
        'Relay\\Relay::OPT_USE_CACHE',
        'Relay\\Relay::OPT_CLIENT_INVALIDATIONS',
        'Relay\\Relay::OPT_ALLOW_PATTERNS',
        'Relay\\Relay::OPT_IGNORE_PATTERNS',
        'Relay\\Cluster::OPT_DISTRIBUTE',
        'Relay\\Cluster::OPT_FAILOVER',
        'Relay\\Cluster::OPT_NODE_READ_TIMEOUT',
        'Relay\\Cluster::OPT_MULTIKEY_REORDERING',
        'Relay\\Cluster::OPT_AVAILABILITY_ZONE',
    ];

    public function __construct(private mixed $value) {}

    public function code(): string {
        foreach (self::OPTIONS as $constant) {
            if (defined($constant) && constant($constant) === $this->value) {
                return str_starts_with($constant, 'Relay\\') ? '\\' . $constant : $constant;
            }
        }

        return var_export($this->value, true);
    }

    public function value(): mixed {
        return $this->value;
    }
}
