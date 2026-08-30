<?php
namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis; use RedisCluster; use Relay\Relay; use Relay\Cluster;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;

abstract class VectorCommand extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string { return self::ANY; }
    protected function element(FuzzConfig $config): string { return $config->getRandomString(); }
    /** @return list<float> */
    protected function values(FuzzConfig $config): array {
        $v = [];
        for ($i = 0, $n = rand(1, 8); $i < $n; $i++) $v[] = $config->getRandomFloat();
        return $v;
    }
    /** @param list<float> $values */
    protected function fp32(array $values): string { return pack('f*', ...$values); }
    /**
     * @param list<float> $values
     * @return list<string>
     */
    protected function rawValues(array $values): array {
        $result = [];
        foreach ($values as $value) $result[] = (string) $value;
        return $result;
    }
}
