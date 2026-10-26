<?php
declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Forked publish/subscribe recursion stress workload. */
final class SubscribeApplication
{
    /** @var resource */
    private $output;
    /** @var resource */
    private $error;

    /**
     * @param (\Closure(ClientConfiguration): (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster))|null $clientFactory
     * @param resource|null $output
     * @param resource|null $error
     */
    public function __construct(private readonly ?\Closure $clientFactory = null, $output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $options = Options::parse($arguments, ['tls-options','client','host','port','seeds','steps','seed','commands','command-steps','script-log'], ['tls','help','no-fork','verbose']);
        if ($options->has('help')) { fwrite($this->output, $this->help()); return 0; }
        $type = ClientType::tryFrom($options->string('client', 'relay'));
        if ($type === null) throw new \InvalidArgumentException('Unknown client type');
        $isRelay = in_array($type, [ClientType::Relay, ClientType::RelayCluster], true);
        $noFork = $isRelay && $options->has('no-fork');
        if (!$noFork && !function_exists('pcntl_fork')) throw new \RuntimeException('pcntl extension is required');
        $steps = max(1, $options->integer('steps', 100));
        $seed = $options->integer('seed', 1);
        // Fuzzer::run() seeds the process-global generator for each command batch.
        $random = new Randomizer(new Mt19937($seed));
        $commands = new SubscribeCommands(
            $seed,
            $options->integer('command-steps', 1),
            $options->csv('commands', ''),
            $options->nullableString('script-log'),
        );
        $batch = 0;
        $executeCommands = function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c) use ($commands, $options, &$batch): void {
            $result = $commands->run($c);
            $batch++;
            foreach ($result->outcomes as $outcome) {
                $hasDiagnostic = $outcome->exception !== null || $outcome->warnings !== [] || $outcome->redisErrors !== [];
                if ($options->has('verbose') || $hasDiagnostic) {
                    fwrite($hasDiagnostic ? $this->error : $this->output, json_encode([
                        'batch' => $batch,
                        'seed' => $result->seed,
                        'outcome' => $outcome,
                    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                }
            }
        };
        $createClient = $this->clientFactory ?? (new ClientFactory())->create(...);
        $host = $options->string('host', '127.0.0.1');
        $port = $options->integer('port', 6379);
        $seeds = $options->csv('seeds', $host . ':' . $port);
        $config = new ClientConfiguration(type: $type, host: $host, port: $port, seeds: $seeds, tls: $options->tls());
        $client = $createClient($config);
        $control = 'fuzz:control:' . $random->getInt(0, 0x7fffffff);
        if ($noFork) {
            if (defined('Relay\\Relay::OPT_PHPREDIS_COMPATIBILITY')) {
                $client->setOption(\Relay\Relay::OPT_PHPREDIS_COMPATIBILITY, false);
            }
            $active = [$control];
            $invocations = 0;
            $failed = false;
            $callback = null;
            // Relay subscription confirmations carry a null message in non-compatibility mode.
            $callback = function (
                \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c,
                string $channel,
                ?string $message,
            ) use (&$callback, &$active, &$invocations, &$failed, $steps, $random, $executeCommands): void {
                $invocations++;
                if ($invocations > $steps) { $c->unsubscribe(); return; }
                try {
                    if ($invocations === $steps) {
                        foreach ($active as $sub) { $c->unsubscribe([$sub]); }
                        $c->unsubscribe();
                        return;
                    }
                    $executeCommands($c);
                    if (count($active) === 1) {
                        $sub = 'fuzz:inner:' . $random->getInt(1, 16); $active[] = $sub;
                        $c->subscribe([$sub], $callback);
                        $c->publish($sub, 'unsubscribe');
                    } elseif ($message === 'unsubscribe') {
                        $sub = (string) $channel;
                        $c->unsubscribe([$sub]);
                        array_pop($active);
                        if (count($active) > 1) $c->publish((string) $active[count($active)-1], 'unsubscribe');
                        else $c->publish((string) $active[0], 'unsubscribe');
                    } elseif ($random->getInt(0, 3) === 0) {
                        $c->publish((string) $channel, 'tick');
                    } else {
                        $c->publish((string) $channel, 'unsubscribe');
                    }
                } catch (\Throwable $e) {
                    $failed = true;
                    fwrite($this->error, "subscribe error: {$e->getMessage()}\n");
                    $c->unsubscribe();
                }
            };
            $client->subscribe([$control], $callback);
            return $failed ? 1 : 0;
        }
        $pid = pcntl_fork();
        if ($pid < 0) throw new \RuntimeException('pcntl_fork failed');
        if ($pid === 0) {
            if ($type === ClientType::Redis || $type === ClientType::RedisCluster) {
                $client = $createClient($config); // PhpRedis connections cannot be shared after fork.
            }
            $channels = [$control];
            $callback = static function (): void {};
            $handler = function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c, string $channel, string $payload) use (&$channels, $isRelay, &$callback, $executeCommands): void {
                $message = json_decode($payload, true);
                if (!is_array($message)) return;
                try {
                    switch ($message['action'] ?? '') {
                        case 'subscribe': $sub = is_string($message['channel'] ?? null) ? $message['channel'] : 'fuzz:room'; $channels[] = $sub; $c->subscribe([$sub], $callback); break;
                        case 'unsubscribe': $sub = is_string($message['channel'] ?? null) ? $message['channel'] : 'fuzz:room'; $c->unsubscribe([$sub]); break;
                        case 'command': if ($isRelay) $executeCommands($c); break;
                        case 'quit': $c->unsubscribe(); return;
                    }
                } catch (\Throwable $e) { fwrite($this->error, "subscribe error: {$e->getMessage()}\n"); }
            };
            // Recursive callback intentionally re-enters subscribe on Relay; PhpRedis may expose stalls/crashes.
            $callback = function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $c, string $channel, string $payload) use ($handler): void { $handler($c, $channel, $payload); };
            $client->subscribe($channels, $callback);
            exit(0);
        }
        for ($i=0; $i<$steps; $i++) {
            $action = $i === $steps-1 ? 'quit' : (['subscribe','command','unsubscribe','message'][$random->getInt(0,3)]);
            $msg = ['action'=>$action, 'channel'=>'fuzz:room:'.$random->getInt(1,8), 'value'=>(string)$random->getInt(0, 0x7fffffff)];
            try { $client->publish($control, json_encode($msg, JSON_THROW_ON_ERROR)); } catch (\Throwable $e) { fwrite($this->error, $e->getMessage()."\n"); }
            usleep(10_000);
        }
        $status = 0; pcntl_waitpid($pid, $status);
        return 0;
    }
    private function help(): string
    {
        return <<<'HELP'
Usage: phpredis-fuzz-subscribe [--client=redis|redis-cluster|relay|relay-cluster]
    [--host=HOST] [--port=N] [--seeds=HOST:PORT,...] [--steps=N] [--seed=N]
    [--no-fork] [--commands=PATTERNS] [--command-steps=N] [--script-log=PREFIX] [--verbose]

--no-fork          Relay callback sequencing without forking (useful with Valgrind).
--tls             Use TLS for subscriber and publisher connections, including cluster seeds.
--tls-options=JSON PHP SSL context options; implies --tls.
--steps=N          Callback limit without forking; publisher actions otherwise (default: 100).
--seed=N           Seed for subscription actions and generated command batches (default: 1).
--commands=LIST    Catalog command/glob/flag filters (default: all normally safe commands).
--command-steps=N  Catalog steps per Relay callback batch (default: 1; must be positive).
--script-log=PATH  Write each batch to PATH.000001.php, PATH.000002.php, etc.; refuses existing files.
--verbose         Print every generated command outcome as JSON; diagnostics are always printed.

Relay runs a batch on each non-terminal --no-fork callback, or on forked command actions.
PhpRedis does not run catalog commands while subscribed. Normal catalog safety gates apply.
Batch scripts capture concrete commands, but replay outside the subscription callback.

HELP;
    }
}
