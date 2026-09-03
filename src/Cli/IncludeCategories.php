<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

final readonly class IncludeCategories
{
    /** @var list<string> */
    private const ALL = ['admin', 'local', 'flush', 'stateful'];

    private function __construct(
        public bool $admin,
        public bool $local,
        public bool $flush,
        public bool $stateful,
    ) {
    }

    public static function parse(?string $value): self
    {
        if ($value === null) {
            return new self(false, false, false, false);
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

            if (!in_array($category, self::ALL, true)) {
                throw new \InvalidArgumentException(
                    "Unknown --include category: {$input}; expected admin, local, flush, stateful, or all",
                );
            }

            $selected[$category] = true;
        }

        return new self(
            admin: isset($selected['admin']),
            local: isset($selected['local']),
            flush: isset($selected['flush']),
            stateful: isset($selected['stateful']),
        );
    }
}
