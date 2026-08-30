<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;

final class CommandFilter
{
    public function apply(Registry $registry, RunConfiguration $configuration): Registry
    {
        $this->applyPatterns($registry, $configuration->commands);

        $excludedFlags = Command::RAW;
        if ($configuration->raw) {
            $excludedFlags &= ~Command::RAW;
        }
        if (!$configuration->includeBlocking) {
            $excludedFlags |= Command::BLOCKING;
        }
        if (!$configuration->includeLocal) {
            $excludedFlags |= Command::LOCAL;
        }
        if (!$configuration->includeAdmin) {
            $excludedFlags |= Command::ADMIN;
        }
        if (!$configuration->includeFlush) {
            $excludedFlags |= Command::FLUSH;
        }
        if (!$configuration->includeCrashing) {
            $excludedFlags |= Command::CRASH;
        }
        if (!$configuration->includeStateful) {
            $excludedFlags |= Command::STATEFUL;
        }

        $registry->filter(
            static fn (Command $command): bool => ($command->flags() & $excludedFlags) === 0,
        );

        foreach ($configuration->weights as $target => $weight) {
            $this->applyWeight($registry, $target, $weight);
        }

        if (count($registry) === 0) {
            throw new \UnderflowException('No commands remain after filtering');
        }

        $positiveWeight = false;
        foreach ($registry as $command) {
            $positiveWeight = $positiveWeight || $command->weight() > 0.0;
        }
        if (!$positiveWeight) {
            throw new \UnderflowException('At least one selected command must have a positive weight');
        }

        return $registry;
    }

    /** @param list<string> $patterns */
    private function applyPatterns(Registry $registry, array $patterns): void
    {
        $includes = [];
        $excludes = [];

        foreach ($patterns as $input) {
            $input = strtolower(trim($input));
            if ($input === '') {
                continue;
            }

            $exclude = str_starts_with($input, '-');
            $pattern = ltrim($input, '+-');
            if ($pattern === '') {
                throw new \InvalidArgumentException("Invalid empty command pattern: {$input}");
            }

            if ($exclude) {
                $excludes[] = $pattern;
            } else {
                $includes[] = $pattern;
            }
        }

        if ($includes !== []) {
            $registry->filter(fn (Command $command): bool => $this->matchesAny($command, $includes));
        }
        if ($excludes !== []) {
            $registry->filter(fn (Command $command): bool => !$this->matchesAny($command, $excludes));
        }
    }

    /** @param list<string> $patterns */
    private function matchesAny(Command $command, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '@')) {
                $flag = Command::stringToFlag(substr($pattern, 1));
                if ($flag === 0) {
                    throw new \InvalidArgumentException("Unknown command flag: {$pattern}");
                }
                if (($command->flags() & $flag) !== 0) {
                    return true;
                }
            } elseif (fnmatch($pattern, $command->name())) {
                return true;
            }
        }

        return false;
    }

    private function applyWeight(Registry $registry, string $target, float $weight): void
    {
        $target = strtolower($target);
        if (!str_starts_with($target, '@')) {
            if (!$registry->setWeight($target, $weight)) {
                throw new \InvalidArgumentException("Unknown command in weight: {$target}");
            }
            return;
        }

        $flag = Command::stringToFlag(substr($target, 1));
        if ($flag === 0) {
            throw new \InvalidArgumentException("Unknown command flag in weight: {$target}");
        }

        $registry->apply(static function (Command $command) use ($flag, $weight): void {
            if (($command->flags() & $flag) !== 0) {
                $command->setWeight($weight);
            }
        });
    }
}
