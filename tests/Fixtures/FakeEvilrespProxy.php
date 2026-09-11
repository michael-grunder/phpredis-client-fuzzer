<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures;

/**
 * The DEBUG EVIL state of one evilresp connection, answering subcommands the
 * way the proxy does so hooks/evilresp.php can be exercised without a proxy.
 */
final class FakeEvilrespProxy
{
    public const IDLE = 'mode=OFF seed=0 probability=0.00 topology_probability=0.00 canonicalize=UNORDERED include=[] exclude=[]'
        . ' strategy=PRESERVE mutations=MANY generator_protocol=RESP2 generator_corpus=BOUNDARY generator_violations=OFF'
        . ' framing=AUTO transport=OFF transport_probability=100.00 transport_truncate=OFF transport_extra=0 transport_chunks=OFF'
        . ' transport_fault=OFF transport_at=AFTER transport_duration=10000';

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
            case 'STRATEGY':
            case 'MUTATIONS':
                $fields[strtolower($subcommand[0])] = $subcommand[1];
                break;
            case 'GENERATOR':
                for ($i = 1; $i < count($subcommand); $i += 2) {
                    $fields['generator_' . strtolower($subcommand[$i])] = $subcommand[$i + 1];
                }
                break;
            case 'FRAMING':
                $fields['framing'] = $subcommand[1];
                unset($fields['framing_probability'], $fields['framing_target'], $fields['framing_kind']);
                if ($subcommand[1] === 'LENGTH') {
                    $fields += ['framing_probability' => '100.00', 'framing_target' => 'ANY', 'framing_kind' => 'RANDOM'];
                    for ($i = 2; $i < count($subcommand); $i += 2) {
                        $fields['framing_' . strtolower($subcommand[$i])] = $subcommand[$i + 1];
                    }
                }
                break;
            case 'TRANSPORT':
                if ($subcommand[1] === 'OFF') {
                    $fields['transport_truncate'] = 'OFF';
                    $fields['transport_extra'] = '0';
                    $fields['transport_chunks'] = 'OFF';
                    $fields['transport_probability'] = '100.00';
                    $fields['transport_fault'] = 'OFF';
                    $fields['transport_at'] = 'AFTER';
                    $fields['transport_duration'] = '10000';
                } else {
                    for ($i = 1; $i < count($subcommand); $i += 2) {
                        $name = strtolower($subcommand[$i]);
                        $fields['transport_' . $name] = $subcommand[$i + 1];
                    }
                }
                $fields['transport'] = $fields['transport_truncate'] !== 'OFF'
                    || $fields['transport_extra'] !== '0' || $fields['transport_chunks'] !== 'OFF'
                    || $fields['transport_fault'] !== 'OFF' ? 'ON' : 'OFF';
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
