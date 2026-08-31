<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;
use Mgrunder\PhpredisCommandFuzzer\Utilities;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xgroup extends Command implements FuzzInterface, FuzzRawInterface {
    private const DESTRUCTIVE_PCT = .05;

    private const U_OPS = ['CREATE' => 1, 'SETID' => 1, 'CREATECONSUMER' => 1];
    private const D_OPS = ['DELCONSUMER' => 1, 'DESTROY' => 1];

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    private function randomOperation(): string {
        if (Utilities::randomChance(self::DESTRUCTIVE_PCT)) {
            $ops = self::D_OPS;
        } else {
            $ops = self::U_OPS;
        }

        return array_rand($ops);
    }

    /** @return list<mixed> */
    private function arguments(FuzzConfig $config, bool $raw): array {
        $key = $config->getRandomKey($this->type());
        $op = $this->randomOperation();
        $args = [$op, $key, 'fuzzer'];

        switch ($op) {
            case 'CREATE': {
                $args[] = Events::instance()->randomReadId();
                $mkstream = (bool)(rand() & 1);
                $entriesRead = rand() & 1 ? rand(0, $config->getMembers()) : -2;

                if ($raw) {
                    if ($mkstream)
                        $args[] = 'MKSTREAM';
                    if ($entriesRead !== -2)
                        array_push($args, 'ENTRIESREAD', $entriesRead);
                } else {
                    array_push($args, $mkstream, $entriesRead);
                }
                break;
            }
            case 'SETID': {
                $args[] = Events::instance()->randomReadId();
                $entriesRead = rand() & 1 ? rand(0, $config->getMembers()) : -2;

                if ($raw && $entriesRead !== -2)
                    array_push($args, 'ENTRIESREAD', $entriesRead);
                else if (! $raw)
                    array_push($args, false, $entriesRead);
                break;
            }
            case 'CREATECONSUMER':
            case 'DELCONSUMER':
                $args[] = $config->getConsumer();
                break;
            case 'DESTROY':
                break;
            default:
                throw new \LogicException("Unknown XGROUP operation: {$op}");
        }

        return $args;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec($client, ...$this->arguments($config, false));
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw($client, ...$this->arguments($config, true));
    }
}
