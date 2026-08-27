<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;


/** @implements \IteratorAggregate<string, Command> */
class Registry implements \Countable, \IteratorAggregate {
    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, array<Command>> */
    private array $by_type = [];

    private const PATH = __DIR__ . '/Command/';

    public function __construct() {
        foreach (new \DirectoryIterator(self::PATH) as $file) {
            if ($file->isDot() || !str_ends_with($file->getFilename(), '.php'))
                continue;

            require_once $file->getPathname();

            $class = __NAMESPACE__ . '\\Command\\' . $file->getBasename('.php');

            $cmd = new $class();
            if (!$cmd instanceof Command) {
                throw new \UnexpectedValueException("{$class} is not a command");
            }

            $this->commands[$cmd->name()] = $cmd;
            $this->by_type[$cmd->type()][$cmd->name()] = $cmd;
        }
    }

    /** @return string[] */
    public function names(): array {
        return array_keys($this->commands);
    }

    /** @return array<string, Command> */
    public function commands(): array {
        return $this->commands;
    }

    public function randomCommand(?int $flags = null, ?string $type = null): Command {
        $source = $type && $type != Command::ANY ? ($this->by_type[$type] ?? []) : $this->commands;

        if ($source === []) {
            throw new \UnderflowException('No command matches the requested type');
        }

        do {
            $name = array_rand($source);
            $pick = $source[$name];

            if ($flags === NULL || ($pick->flags() & $flags) === $flags)
                return $pick;
        } while (true);
    }

    public function get(string $name): ?Command {
        return $this->commands[strtolower($name)] ?? null;
    }

    public function setWeight(string $name, float $weight): bool {
        $cmd = $this->commands[strtolower($name)] ?? null;
        if ( ! $cmd)
            return false;

        $cmd->setWeight($weight);
        return true;
    }

    public function filter(callable $cb): self {
        $this->commands = array_filter($this->commands, $cb);
        foreach ($this->by_type as $type => $commands) {
            $this->by_type[$type] = array_filter($commands, $cb);
        }

        return $this;
    }

    public function apply(callable $cb): self {
        foreach ($this->commands as $cmd) {
            $cb($cmd);
        }

        return $this;
    }


    /** @return \ArrayIterator<string, Command> */
    public function getIterator(): \ArrayIterator {
        return new \ArrayIterator($this->commands);
    }

    public function count(): int {
        return count($this->commands);
    }
}
