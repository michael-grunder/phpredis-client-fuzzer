<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * The child fuzzer command given after `--` on the harness command line.
 *
 * Tokens may contain `{port}`, `{steps}`, `{seed}`, `{job}` and `{run}`
 * placeholders that the harness substitutes with concrete values before each
 * run, so a single template drives an entire campaign.
 */
final class CommandTemplate
{
    /** @var list<string> */
    public const VARIABLES = ['port', 'steps', 'seed', 'job', 'run'];

    /** @param list<string> $tokens */
    public function __construct(private readonly array $tokens)
    {
        if ($tokens === []) {
            throw new \InvalidArgumentException('the fuzzer command after -- is empty');
        }
    }

    /**
     * Every distinct `{name}` placeholder referenced by the template.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $found = [];
        foreach ($this->tokens as $token) {
            if (preg_match_all('/\{([a-z]+)\}/', $token, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    public function validate(): void
    {
        foreach ($this->variables() as $name) {
            if (!in_array($name, self::VARIABLES, true)) {
                $known = implode(
                    ', ',
                    array_map(static fn (string $v): string => '{' . $v . '}', self::VARIABLES),
                );
                throw new \InvalidArgumentException(
                    "unknown template variable {{$name}}; supported placeholders: {$known}",
                );
            }
        }
    }

    public function uses(string $variable): bool
    {
        return in_array($variable, $this->variables(), true);
    }

    /**
     * Whether an explicit `--name` or `--name=...` option already appears in the
     * template, so the harness knows not to append its own.
     */
    public function hasOption(string $name): bool
    {
        foreach ($this->tokens as $token) {
            if ($token === "--{$name}" || str_starts_with($token, "--{$name}=")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|string> $values
     * @return list<string>
     */
    public function render(array $values): array
    {
        $search = [];
        $replace = [];
        foreach ($values as $name => $value) {
            $search[] = '{' . $name . '}';
            $replace[] = (string) $value;
        }

        return array_map(
            static fn (string $token): string => str_replace($search, $replace, $token),
            $this->tokens,
        );
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }
}
