<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Utilities;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

abstract class OptionCommand extends Command {
    private const REDIS_OPTS = [
        'Redis::OPT_PREFIX',
        'Redis::OPT_SERIALIZER',
        'Redis::OPT_COMPRESSION',
        'Redis::OPT_COMPRESSION_LEVEL',
        'Redis::OPT_READ_TIMEOUT',
        'Redis::OPT_SCAN',
        'Redis::OPT_TCP_KEEPALIVE',
        'Redis::OPT_MAX_RETRIES',
        'RedisCluster::OPT_SLAVE_FAILOVER',
    ];

    private const RELAY_OPTS = [
        'Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY',
        'Relay\\Relay::OPT_THROW_ON_ERROR',
        'Relay\\Relay::OPT_USE_CACHE',
        'Relay\\Relay::OPT_CLIENT_INVALIDATIONS',
        'Relay\\Relay::OPT_ALLOW_PATTERNS',
        'Relay\\Relay::OPT_IGNORE_PATTERNS',
    ];

    private const RELAY_CLUSTER_OPTS = [
        'Relay\\Cluster::OPT_DISTRIBUTE',
        'Relay\\Cluster::OPT_FAILOVER',
        'Relay\\Cluster::OPT_NODE_READ_TIMEOUT',
        'Relay\\Cluster::OPT_MULTIKEY_REORDERING',
        'Relay\\Cluster::OPT_AVAILABILITY_ZONE',
    ];

    private const RELAY_CLUSTER_DISTRIBUTE = [
        'Relay\\Cluster::DISTRIBUTE_NONE',
        'Relay\\Cluster::DISTRIBUTE_RANDOM',
        'Relay\\Cluster::DISTRIBUTE_RANDOM_REPLICA',
        'Relay\\Cluster::DISTRIBUTE_REPLICAS',
        'Relay\\Cluster::DISTRIBUTE_ALL',
    ];

    private const RELAY_CLUSTER_FAILOVER = [
        'Relay\\Cluster::FAILOVER_NONE',
        'Relay\\Cluster::FAILOVER_PRIMARY',
        'Relay\\Cluster::FAILOVER_RANDOM_REPLICA',
        'Relay\\Cluster::FAILOVER_REPLICAS',
        'Relay\\Cluster::FAILOVER_ALL',
    ];

    private const RELAY_CLUSTER_MULTIKEY_REORDERING = [
        'Relay\\Cluster::MULTIKEY_REORDER_NONE',
        'Relay\\Cluster::MULTIKEY_REORDER_READS',
        'Relay\\Cluster::MULTIKEY_REORDER_WRITES',
        'Relay\\Cluster::MULTIKEY_REORDER_ALL',
    ];

    private const UNSTABLE_OPTS = [
        'Redis::OPT_NULL_MULTIBULK_AS_NULL',
        'Redis::OPT_REPLY_LITERAL',
    ];

    private const SERIALIZERS = [
        'Redis::SERIALIZER_NONE',
        'Redis::SERIALIZER_PHP',
        'Redis::SERIALIZER_IGBINARY',
//        'Redis::SERIALIZER_MSGPACK',
        'Redis::SERIALIZER_JSON',
    ];

    private const COMPRESSIONS = [
        'Redis::COMPRESSION_NONE',
        'Redis::COMPRESSION_LZF',
        'Redis::COMPRESSION_LZ4',
        'Redis::COMPRESSION_ZSTD',
    ];

    private const SCAN_OPTIONS = [
        'Redis::SCAN_RETRY',
        'Redis::SCAN_NORETRY',
    ];

    private const BACKOFF_ALGOS = [
        'Redis::BACKOFF_ALGORITHM_DEFAULT',
        'Redis::BACKOFF_ALGORITHM_CONSTANT',
        'Redis::BACKOFF_ALGORITHM_UNIFORM',
        'Redis::BACKOFF_ALGORITHM_EXPONENTIAL',
        'Redis::BACKOFF_ALGORITHM_FULL_JITTER',
        'Redis::BACKOFF_ALGORITHM_EQUAL_JITTER',
        'Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER',
    ];

    private const FAILOVER_OPTS = [
        'RedisCluster::FAILOVER_NONE',
        'RedisCluster::FAILOVER_ERROR',
        'RedisCluster::FAILOVER_DISTRIBUTE',
        'RedisCluster::FAILOVER_DISTRIBUTE_SLAVES',
    ];

    /** @var list<int> */
    private array $redis_opts = [];
    /** @var list<int> */
    private array $relay_opts = [];
    /** @var list<int> */
    private array $relay_cluster_opts = [];

    private function isDebugBuild(): bool {
        ob_start();
        phpinfo(INFO_GENERAL);
        $info = ob_get_clean();

        return $info !== false && strpos($info, 'Debug Build => yes') !== false;
    }

    public function __construct() {
        $extra = $this->isDebugBuild() ? [] : self::UNSTABLE_OPTS;

        $this->redis_opts = $this->constantValues(array_merge(self::REDIS_OPTS, $extra));
        $this->relay_opts = $this->constantValues(array_merge(
            self::REDIS_OPTS,
            self::RELAY_OPTS,
            $extra,
        ));
        $this->relay_cluster_opts = $this->constantValues(array_merge(
            self::REDIS_OPTS,
            self::RELAY_OPTS,
            self::RELAY_CLUSTER_OPTS,
            $extra,
        ));
    }

    protected function randomFailover(): int {
        return $this->randomConstant(self::FAILOVER_OPTS);
    }

    protected function randomBackoffAlgorithm(): int {
        return $this->randomConstant(self::BACKOFF_ALGOS);
    }

    protected function randomCompression(): int {
        return $this->randomConstant(self::COMPRESSIONS);
    }

    protected function randomSerializer(): int {
        return $this->randomConstant(self::SERIALIZERS);
    }

    protected function randomScanOption(): int {
        return $this->randomConstant(self::SCAN_OPTIONS);
    }

    protected function randomRelayClusterDistribute(): int {
        return $this->randomConstant(self::RELAY_CLUSTER_DISTRIBUTE);
    }

    protected function randomRelayClusterFailover(): int {
        return $this->randomConstant(self::RELAY_CLUSTER_FAILOVER);
    }

    protected function randomRelayClusterMultikeyReordering(): int {
        return $this->randomConstant(self::RELAY_CLUSTER_MULTIKEY_REORDERING);
    }

    protected function randomOption(Redis|RedisCluster|Relay|Cluster $client): int
    {
        if ($client instanceof Cluster)
            return $this->relay_cluster_opts[array_rand($this->relay_cluster_opts)];
        if ($this->isRelay($client))
            return $this->relay_opts[array_rand($this->relay_opts)];
        return $this->redis_opts[array_rand($this->redis_opts)];
    }

    /**
     * @param list<string> $constants
     * @return non-empty-list<int>
     */
    private function constantValues(array $constants): array {
        $values = [];
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $value = constant($constant);
            if (is_int($value)) {
                $values[] = $value;
            }
        }
        if ($values === []) {
            throw new \LogicException('No supported client constants were found');
        }

        return $values;
    }

    /** @param non-empty-list<string> $constants */
    private function randomConstant(array $constants): int {
        $values = $this->constantValues($constants);
        return $values[array_rand($values)];
    }
}
