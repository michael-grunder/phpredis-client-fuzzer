<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\CommandFilter;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\FuzzResult;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** @internal Bounded catalog runs on the connection currently delivering a callback. */
final class SubscribeCommands
{
    private readonly Randomizer $random;
    private int $batch = 0;

    /** @param list<string> $commands */
    public function __construct(
        int $seed,
        private readonly int $steps = 1,
        private readonly array $commands = [],
        private readonly ?string $scriptLog = null,
    ) {
        if ($steps < 1) {
            throw new \InvalidArgumentException('--command-steps must be positive');
        }
        if ($scriptLog === '') {
            throw new \InvalidArgumentException('--script-log must be a non-empty filename prefix');
        }
        $this->random = new Randomizer(new Mt19937($seed));
        // Reject invalid or entirely unsafe selections before subscribing.
        (new CommandFilter())->apply(new Registry(), new RunConfiguration(commands: $commands));
    }

    public function run(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $client): FuzzResult
    {
        $seed = $this->random->getInt(0, 0x7fffffff);
        $batch = ++$this->batch;
        $scriptLog = null;
        if ($this->scriptLog !== null) {
            $scriptLog = sprintf('%s.%06d.php', $this->scriptLog, $batch);
            // Reserve a fresh path; never overwrite evidence from an earlier run.
            $file = @fopen($scriptLog, 'x');
            if ($file === false) {
                throw new \RuntimeException('Cannot create subscription command log: ' . $scriptLog);
            }
            fclose($file);
        }

        return (new Fuzzer())->run([$client], new RunConfiguration(
            maxSteps: $this->steps,
            seed: $seed,
            commands: $this->commands,
            scriptLog: $scriptLog,
        ));
    }
}
