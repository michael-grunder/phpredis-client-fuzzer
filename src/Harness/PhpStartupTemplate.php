<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

use Random\Engine\Mt19937;
use Random\Randomizer;

/** Snapshot and validate startup templates before launching any workers. */
final class PhpStartupTemplate
{
    private readonly ?string $iniContents;
    private readonly bool $injectIni;

    /** @param list<string> $arguments Tokenized --php-args, without --php-ini's -c. */
    public function __construct(private readonly array $arguments, private readonly ?string $ini = null)
    {
        foreach ($arguments as $argument) {
            ArgumentInjection::validate($argument);
        }

        $contents = $ini === null ? null : @file_get_contents($ini);
        if ($contents === false) {
            throw new \RuntimeException("cannot read php ini file: {$ini}");
        }
        $this->iniContents = $contents;
        $this->injectIni = $contents !== null && ArgumentInjection::validate($contents);
    }

    /**
     * A private generator keeps choices independent of scheduling, probes, and
     * process-global randomness. Reductions use the same seed and snapshot.
     *
     * @return array{args: list<string>, ini: ?string}
     */
    public function render(int $seed, string $workDir): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $args = array_map(static fn (string $arg): string => ArgumentInjection::expand($arg, $random), $this->arguments);
        $ini = $this->ini;
        if ($this->injectIni && $this->iniContents !== null) {
            $ini = $workDir . '/php.ini';
            $contents = ArgumentInjection::expand($this->iniContents, $random);
            if (file_put_contents($ini, $contents) !== strlen($contents)) {
                throw new \RuntimeException("cannot write generated php ini file: {$ini}");
            }
        }
        if ($ini !== null) {
            $args = ['-c', $ini, ...$args];
        }

        return ['args' => $args, 'ini' => $ini];
    }
}
