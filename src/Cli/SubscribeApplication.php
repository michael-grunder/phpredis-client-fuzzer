<?php
declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;

/** Forked publish/subscribe recursion stress workload. */
final class SubscribeApplication
{
    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $options = Options::parse($arguments, ['client','host','port','steps','seed'], ['help']);
        if ($options->has('help')) { fwrite(STDOUT, $this->help()); return 0; }
        if (!function_exists('pcntl_fork')) throw new \RuntimeException('pcntl extension is required');
        $type = ClientType::tryFrom($options->string('client', 'relay'));
        if ($type === null) throw new \InvalidArgumentException('Unknown client type');
        $steps = max(1, $options->integer('steps', 100));
        $seed = $options->integer('seed', 1); mt_srand($seed);
        $factory = new ClientFactory();
        $config = new ClientConfiguration($type, $options->string('host','127.0.0.1'), $options->integer('port',6379));
        $client = $factory->create($config);
        $control = 'fuzz:control:' . mt_rand();
        $pid = pcntl_fork();
        if ($pid < 0) throw new \RuntimeException('pcntl_fork failed');
        if ($pid === 0) {
            if ($type === ClientType::Redis || $type === ClientType::RedisCluster) {
                $client = $factory->create($config); // PhpRedis connections cannot be shared after fork.
            }
            $channels = [$control];
            $callback = static function (): void {};
            $handler = function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c, string $channel, string $payload) use (&$channels, $type, &$callback): void {
                $message = json_decode($payload, true);
                if (!is_array($message)) return;
                try {
                    switch ($message['action'] ?? '') {
                        case 'subscribe': $sub = is_string($message['channel'] ?? null) ? $message['channel'] : 'fuzz:room'; $channels[] = $sub; $c->subscribe([$sub], $callback ?? static function (): void {}); break;
                        case 'unsubscribe': $sub = is_string($message['channel'] ?? null) ? $message['channel'] : 'fuzz:room'; $c->unsubscribe([$sub]); break;
                        case 'command': if ($type === ClientType::Relay || $type === ClientType::RelayCluster) $c->set('fuzz:side', $message['value'] ?? 'x'); break;
                        case 'quit': $c->unsubscribe(); return;
                    }
                } catch (\Throwable $e) { fwrite(STDERR, "subscribe error: {$e->getMessage()}\n"); }
            };
            // Recursive callback intentionally re-enters subscribe on Relay; PhpRedis may expose stalls/crashes.
            $callback = function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c, string $channel, string $payload) use ($handler): void { $handler($c, $channel, $payload); };
            $client->subscribe($channels, $callback);
            exit(0);
        }
        for ($i=0; $i<$steps; $i++) {
            $action = $i === $steps-1 ? 'quit' : (['subscribe','command','unsubscribe','message'][mt_rand(0,3)]);
            $msg = ['action'=>$action, 'channel'=>'fuzz:room:'.mt_rand(1,8), 'value'=>(string)mt_rand()];
            try { $client->publish($control, json_encode($msg, JSON_THROW_ON_ERROR)); } catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); }
            usleep(10_000);
        }
        $status = 0; pcntl_waitpid($pid, $status);
        return 0;
    }
    private function help(): string { return "Usage: phpredis-fuzz-subscribe [--client=redis|redis-cluster|relay|relay-cluster] [--host=HOST] [--port=N] [--steps=N] [--seed=N]\n"; }
}
