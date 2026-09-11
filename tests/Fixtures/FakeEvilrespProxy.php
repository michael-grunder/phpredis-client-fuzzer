<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures;

/**
 * The DEBUG EVIL state of one evilresp connection, answering subcommands the
 * way the proxy does so hooks/evilresp.php can be exercised without a proxy.
 */
final class FakeEvilrespProxy
{
    public const IDLE = 'mode=OFF seed=0 probability=0.00 topology_probability=0.00 canonicalize=UNORDERED include=[] exclude=[]';

    public string $status = self::IDLE;

    /**
     * @param list<string> $subcommand Arguments after DEBUG EVIL.
     */
    public function answer(array $subcommand): string|bool
    {
        $fields = [];
        foreach (explode(' ', $this->status) as $field) {
            [$name, $value] = explode('=', $field, 2);
            $fields[$name] = $value;
        }

        switch ($subcommand[0] ?? '') {
            case 'STATUS':
                return $this->status;
            case 'SEED':
                $fields['seed'] = $subcommand[1];
                break;
            case 'MODE':
                if ($subcommand[1] === 'RESET') {
                    $fields['mode'] = 'OFF';
                    $fields['probability'] = '0.00';
                    break;
                }
                $fields['mode'] = $subcommand[1];
                $fields['probability'] = $subcommand[1] === 'OFF' ? '0.00' : $subcommand[3];
                break;
            case 'TOPOLOGY':
                $fields['topology_probability'] = $subcommand[1];
                break;
            case 'CANONICALIZE':
                $fields['canonicalize'] = $subcommand[1];
                break;
            case 'INCLUDE':
            case 'EXCLUDE':
                $fields[strtolower($subcommand[0])] = '[' . implode(',', array_slice($subcommand, 1)) . ']';
                break;
            default:
                throw new \LogicException('Unexpected DEBUG EVIL subcommand ' . json_encode($subcommand, JSON_THROW_ON_ERROR));
        }

        $this->status = implode(' ', array_map(
            static fn (string $name, string $value): string => "{$name}={$value}",
            array_keys($fields),
            $fields,
        ));

        return true;
    }

    /**
     * @param list<mixed> $arguments A full DEBUG EVIL argument list.
     * @return list<string>
     */
    public static function subcommand(array $arguments): array
    {
        $subcommand = [];
        foreach (array_slice($arguments, 2) as $argument) {
            $subcommand[] = is_string($argument) ? $argument : var_export($argument, true);
        }

        return $subcommand;
    }
}
