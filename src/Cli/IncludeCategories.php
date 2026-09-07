<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

final readonly class IncludeCategories
{
    /**
     * The categories `all` turns on. Deliberately crashing commands are not
     * among them: `crash` kills the process on purpose, so it stays an
     * explicit, individually named opt-in.
     *
     * @var list<string>
     */
    private const ALL = ['admin', 'local', 'flush', 'stateful'];

    /** @var list<string> */
    private const CATEGORIES = ['admin', 'local', 'flush', 'stateful', 'crash'];

    private function __construct(
        public bool $admin,
        public bool $local,
        public bool $flush,
        public bool $stateful,
        public bool $crash,
    ) {
    }

    public static function parse(?string $value): self
    {
        if ($value === null) {
            return new self(false, false, false, false, false);
        }

        $values = Options::split($value);
        if ($values === []) {
            throw new \InvalidArgumentException('--include cannot be empty');
        }

        /** @var array<string, true> $selected */
        $selected = [];
        foreach ($values as $input) {
            $category = strtolower($input);
            if ($category === 'all') {
                foreach (self::ALL as $included) {
                    $selected[$included] = true;
                }
                continue;
            }

            if (!in_array($category, self::CATEGORIES, true)) {
                throw new \InvalidArgumentException(
                    "Unknown --include category: {$input}; expected admin, local, flush, "
                    . 'stateful, crash, or all (all excludes crash)',
                );
            }

            $selected[$category] = true;
        }

        return new self(
            admin: isset($selected['admin']),
            local: isset($selected['local']),
            flush: isset($selected['flush']),
            stateful: isset($selected['stateful']),
            crash: isset($selected['crash']),
        );
    }
}
