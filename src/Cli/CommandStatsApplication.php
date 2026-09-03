<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\CounterTracker;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Sampler;
use Mgrunder\PhpredisCommandFuzzer\CommandStats\Tui;

final class CommandStatsApplication
{
    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output
     * @param resource|null $error
     */
    public function __construct($output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse(
                $arguments,
                ['client', 'host', 'port', 'seeds', 'username', 'password', 'timeout', 'read-timeout', 'interval'],
                ['help', 'cluster'],
            );
            if ($options->has('help')) {
                fwrite($this->output, self::HELP);
                return 0;
            }

            $type = $this->clientType($options->string('client', 'redis'), $options->has('cluster'));
            $host = $options->string('host', '127.0.0.1');
            $port = $options->integer('port', 6379);
            $seeds = Options::split($options->string('seeds', "{$host}:{$port}"));
            if ($seeds === []) {
                throw new \InvalidArgumentException('--seeds cannot be empty');
            }
            $interval = $options->number('interval', 1.0);
            if ($interval <= 0) {
                throw new \InvalidArgumentException('--interval must be greater than zero');
            }

            $username = $options->nullableString('username');
            $password = $options->nullableString('password');
            $auth = $username !== null && $password !== null ? [$username, $password] : $password;
            $sampler = new Sampler(
                $type,
                $host,
                $port,
                $seeds,
                $options->number('timeout', 1.0),
                $options->number('read-timeout', 1.0),
                $auth,
            );
            $tracker = new CounterTracker();
            $sample = $sampler->sample();
            $tracker->update($sample);
            $source = $type->isCluster() ? implode(',', $seeds) : "{$host}:{$port}";
            $tui = new Tui($type->isCluster(), $source, $interval);
            $started = microtime(true);
            $nextSample = $started + $interval;
            $nextClockRender = $started + 1.0;

            $tui->start();
            try {
                $tui->render($tracker, $sample, 0.0);
                while (!$tui->quitRequested()) {
                    $now = microtime(true);
                    $redraw = $tui->resizeRequested();
                    if ($now >= $nextSample) {
                        $sample = $sampler->sample();
                        $tracker->update($sample);
                        $nextSample = $now + $interval;
                        $redraw = true;
                    }
                    if ($now >= $nextClockRender) {
                        $nextClockRender = $now + 1.0;
                        $redraw = true;
                    }
                    if ($redraw) {
                        $tui->render($tracker, $sample, $now - $started);
                    }
                    usleep(50_000);
                }
            } finally {
                $tui->stop();
                $sampler->close();
            }

            return 0;
        } catch (\Throwable $throwable) {
            fwrite($this->error, 'phpredis-commandstats: ' . $throwable->getMessage() . "\n");
            return 1;
        }
    }

    private function clientType(string $value, bool $cluster): ClientType
    {
        $type = ClientType::tryFrom(strtolower(trim($value)));
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown client type: {$value}");
        }

        return match (true) {
            !$cluster => $type,
            $type === ClientType::Redis => ClientType::RedisCluster,
            $type === ClientType::Relay => ClientType::RelayCluster,
            default => $type,
        };
    }

    private const HELP = <<<'HELP'
phpredis-commandstats - live Redis command usage since the monitor started

Usage:
  phpredis-commandstats [options]

Connection:
  --client=TYPE           redis, redis-cluster, relay, relay-cluster (default: redis)
  --cluster               Treat redis/relay as a cluster client
  --host=HOST             Standalone host (default: 127.0.0.1)
  --port=PORT             Standalone port (default: 6379)
  --seeds=HOST:PORT,...   Cluster discovery seeds (defaults to host and port)
  --username=USER         ACL username
  --password=PASS         Password
  --timeout=SECONDS       Connect timeout (default: 1)
  --read-timeout=SECONDS  Read timeout (default: 1)

Display:
  --interval=SECONDS      Sampling interval (default: 1)
  --help                  Show this help without connecting

The first INFO commandstats result is the baseline. Cluster mode discovers all
connected data nodes with CLUSTER NODES, samples each node directly, and shows
separate totals for primary and replica traffic. Press q, Esc, or Ctrl-C to quit.

Examples:
  phpredis-commandstats --client=redis --port=6379
  phpredis-commandstats --client=relay-cluster --seeds=127.0.0.1:7000
  phpredis-commandstats --client=relay --cluster --host=127.0.0.1 --port=7000
HELP;
}
