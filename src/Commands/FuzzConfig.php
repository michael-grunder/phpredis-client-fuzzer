<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

/**
 * Class FuzzConfig
 *
 * A class that can generate randomized values of various types, for the purpose
 * of fuzz testing Redis clients.
 *
 */
class FuzzConfig {
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const ALL_TYPES = [
        Command::STRING => true,
        Command::LIST   => true,
        Command::SET    => true,
        Command::ZSET   => true,
        Command::HASH   => true,
        Command::STREAM => true
    ];

    private const INT_MIN       = -32768;
    private const INT_MAX       =  32767;
    private const FLOAT_SCALE   =   1000;

    private int $keys           =   1000;
    private bool $cluster       =  false;
    private int $shards         =     16;
    private int $members        =    100;
    private int $min_len        =      4;
    private int $max_len        =     64;
    private int $max_prefix_len =     0;
    private int $cmd_max_keys   =     10;
    private float $timeout_min  =   .001;
    private float $timeout_max  =    .01;
    private int $expire_min     =      1;
    private int $expire_max     =   1000;
    private float $wrongtype    =    0.0;
    private float $crossslot    =    0.0;

    private int $databases = 16;

    /* Slot scope for the command currently generating arguments.  See
       beginStep() for how these are chosen and what they mean. */
    private SlotPolicy $slot_policy = SlotPolicy::Unconstrained;
    private int $slot_tag = 0;
    private int $slot_cursor = 0;

    protected function serializerEnabled(Redis|RedisCluster|Relay|Cluster $client): bool {
        return $client->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE;
    }

    public function __construct(Redis|RedisCluster|Relay|Cluster|null $client = null) {
        if ($client === null) {
            return;
        }
        if ($client instanceOf RedisCluster || $client instanceof Cluster) {
            $this->databases = 1;
            return;
        }

        try {
            $res = $client->config('get', 'databases');
            if ( ! is_array($res) || ! isset($res['databases']) ||
                 ! is_scalar($res['databases']))
                throw new \InvalidArgumentException("Malformed config response " . print_r($res, true));

            $this->databases = (int)$res['databases'];
        } catch (\Exception) {
        }
    }

    private function validateNumeric(int|float $value,
                                  int|float|null $min = null,
                                  int|float|null $max = null): int|float
    {
        if ( ! is_null($max) && $value > $max)
            throw new \InvalidArgumentException("Value $value is greater than maximum $max");
        else if ( ! is_null($min) && $value < $min)
            throw new \InvalidArgumentException("Value $value is less than minimum $min");

        return $value;
    }

    public function setKeys(int $keys): self {
        $this->keys = (int) $this->validateNumeric($keys, 1);
        return $this;
    }

    public function setCluster(bool $v): self {
        $this->cluster = $v;
        return $this;
    }

    public function setShards(int $shards): self {
        $this->shards = (int) $this->validateNumeric($shards, 1);
        return $this;
    }

    public function setMembers(int $members): self {
        $this->members = (int) $this->validateNumeric($members, 1);
        return $this;
    }

    public function setMinLen(int $min_len): self {
        $this->min_len = (int) $this->validateNumeric($min_len, 1);
        return $this;
    }

    public function setMaxLen(int $max_len): self {
        $this->max_len = (int) $this->validateNumeric($max_len, $this->min_len);
        return $this;
    }

    public function setMaxPrefixLen(int $max_len): self {
        $this->max_prefix_len = (int) $this->validateNumeric($max_len, 0);
        return $this;
    }

    public function setMaxKeys(int $cmd_max_keys): self {
        $this->cmd_max_keys = (int) $this->validateNumeric($cmd_max_keys, 1);
        return $this;
    }

    public function setTimeoutMin(float $timeout_min): self {
        $this->timeout_min = $this->validateNumeric($timeout_min, 0);
        return $this;
    }

    public function setTimeoutMax(float $timeout_max): self {
        $this->timeout_max = $this->validateNumeric($timeout_max, $this->timeout_min);
        return $this;
    }

    public function setExpireMin(int $expire_min): self {
        $this->expire_min = (int) $this->validateNumeric($expire_min, 1);
        return $this;
    }

    public function setExpireMax(int $expire_max): self {
        $this->expire_max = (int) $this->validateNumeric($expire_max, $this->expire_min);
        return $this;
    }

    public function setWrongtype(float $chance): self {
        if ($chance < 0.0)
            $chance = 0.0;
        else if ($chance > 1.0)
            $chance = 1.0;

        $this->wrongtype = $chance;
        return $this;
    }

    /**
     * Probability (0 to 1) that a command Redis requires to be single-slot is
     * instead handed keys in distinct slots, forcing a CROSSSLOT error.  Only
     * meaningful for cluster clients with more than one shard.
     */
    public function setCrossSlot(float $chance): self {
        if ($chance < 0.0)
            $chance = 0.0;
        else if ($chance > 1.0)
            $chance = 1.0;

        $this->crossslot = $chance;
        return $this;
    }

    /**
     * Open a key-generation scope for one command and pick how its keys are
     * spread over cluster hash slots.
     *
     * Call this once before a command builds its arguments.  Until it is
     * called (or when generating keys outside of a command) each key picks its
     * own random tag, which is what SlotPolicy::Unconstrained does.
     */
    public function beginCommand(Command $command): SlotPolicy {
        return $this->beginStep(($command->flags() & Command::CROSSSLOT) !== 0);
    }

    /**
     * @param bool $crossslot_capable True when the *client* splits the command
     *                                across nodes by slot, so mixed-slot keys
     *                                are valid input rather than an error.
     */
    public function beginStep(bool $crossslot_capable): SlotPolicy {
        $this->slot_tag = rand() % $this->shards;
        $this->slot_cursor = 0;

        $this->slot_policy = match (true) {
            $crossslot_capable => SlotPolicy::Unconstrained,
            $this->cluster && $this->pctChance($this->crossslot) => SlotPolicy::CrossSlot,
            default => SlotPolicy::SameSlot,
        };

        return $this->slot_policy;
    }

    public function slotPolicy(): SlotPolicy {
        return $this->slot_policy;
    }

    public function getCmdMaxKeys(): int {
        return $this->cmd_max_keys;
    }

    public function getMaxKeys(): int {
        return $this->keys;
    }

    public function getConsumer(): string {
        return sprintf("consumer:%d", getmypid());
    }


    /**
     * @return string[]
     */
    public function getRandomStrings(?int $max = null): array {
        $result = [];

        assert($max === null || $max >= 1);

        $count = $max ?? mt_rand(1, $this->members);
        for ($i = 0; $i < $count; $i++)
            $result[] = $this->getRandomString();

        return $result;
    }

    private function getRandomStringLen(int $len): string {
        assert($len >= 1);
        $result = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $result .= self::ALPHABET[mt_rand(0, $max)];
        }

        return $result;
    }

    public function getRandomBytes(int $length): string {
        if ($length < 1) {
            throw new \InvalidArgumentException('Random byte length must be positive');
        }

        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= chr(mt_rand(0, 255));
        }

        return $result;
    }

    public function getRandomString(): string {
        $len = mt_rand($this->min_len, $this->max_len);

        assert($len >= 1);

        return $this->getRandomStringLen($len);
    }

    public function getRandomPrefix(): ?string {
        if ($this->max_prefix_len === 0 || mt_rand() & 1)
            return mt_rand() & 1 ? '' : null;

        return $this->getRandomStringLen(mt_rand(1, $this->max_prefix_len));
    }

    public function getRandomType(): string {
        return array_rand(self::ALL_TYPES);
    }

    private function getKey(string $type, int $shard, int $n): string {
        if ($this->cluster)
            return sprintf("%s:{%d}:%d", $type, $shard, $n);

        return sprintf("%s:%d", $type, $n);
    }

    private function pctChance(float $chance): bool {
        if ($chance == 0.0)
            return false;

        return (mt_rand() / mt_getrandmax()) < $chance;
    }

    /* Hash tag for the next generated key, honoring the active slot scope.
       CrossSlot walks distinct tags so a key set genuinely spans slots instead
       of colliding on one by chance. */
    private function nextShard(): int {
        return match ($this->slot_policy) {
            SlotPolicy::SameSlot      => $this->slot_tag,
            SlotPolicy::CrossSlot     => ($this->slot_tag + $this->slot_cursor++) % $this->shards,
            SlotPolicy::Unconstrained => rand() % $this->shards,
        };
    }

    public function getRandomKey(string $type, ?int $shard = null): string {
        if ($type == Command::ANY || $this->pctChance($this->wrongtype))
            $type = $this->getRandomType();

        $shard ??= $this->nextShard();

        return $this->getKey($type, $shard, rand(0, $this->keys - 1));
    }

    private function randFloatRange(float $min, float $max): float {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    public function getTimeoutFloat(): float {
        return $this->randFloatRange($this->timeout_min, $this->timeout_max);
    }

    /**
     * @return string[]
     */
    public function getRandomKeys(string $type, ?int $count = null): array {
        $result = [];

        $count ??= rand(1, $this->cmd_max_keys);
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->getRandomKey($type);
        }

        return $result;
    }

    public function getRandomPattern(): string {
        $pattern = '';

        $rng = rand();

        if ($rng & 1)
            $pattern .= '*';

        if ($rng & 2)
            $pattern .= rand(1, 9);
        else if ($rng & 4)
            $pattern = $this->getRandomType();
        else if ($rng & 8)
            $pattern = $this->getRandomString();

        if ($rng & 16)
            $pattern .= '*';

        return $pattern;
    }

    private static function typeToMember(string $type): string {
        return match ($type) {
            'string'     => 'value',
            'list'       => 'element',
            'set'        => 'member',
            'zset'       => 'member',
            'hash'       => 'field',
            'stream'     => 'entry',
            'hll'        => 'value',
            'hash:float' => 'ffield',
            'hash:int'   => 'ifield',
            default  => $type,
        };
    }

    private function getMember(string $type, int $member): string {
        return sprintf("%s:%d", self::typeToMember($type), $member);
    }

    /**
     * @param string $type
     * @param string[] $qualifiers
     * @return string
     */
    public function getRandomMember(string $type, array $qualifiers = []): string {
        $qualifiers[] = '';

        if (($suffix = array_rand($qualifiers))) {
            $type .= ":$suffix";
        }

        return $this->getMember($type, rand(0, $this->members - 1));
    }

    /**
     * @return string[]
     */
    public function getRandomMembers(string $type, ?int $count = null): array {
        $result = [];

        $count ??= rand(1, $this->members);
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->getRandomMember($type);
        }

        return $result;
    }

    public function getRandomValue(Redis|RedisCluster|Relay|Cluster $client,
                                   string $type): mixed
    {
        if ($this->serializerEnabled($client) && rand(0, 1)) {
            return [$this->getRandomString()];
        } else {
            return $this->getRandomString();
        }
    }

    /**
     * @return mixed[]
     */
    public function getRandomValues(Redis|RedisCluster|Relay|Cluster $client,
                                    string $type, ?int $count = null): array
    {
        $result = [];

        $count ??= rand(1, $this->members);
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->getRandomValue($client, $type);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHash(Redis|Relay $client): array {
        $result = [];

        for ($i = 0; $i < rand(1, $this->members); $i++) {
            $value = $this->getRandomValue($client, 'field');
            $result[$this->getRandomMember("field")] = $value;
        }

        return $result;
    }

    public function getRandomInt(): int {
        return rand(self::INT_MIN, self::INT_MAX);
    }

    public function getRandomFloat(): float {
        return round($this->randFloatRange(self::INT_MIN, self::INT_MAX) * self::FLOAT_SCALE, 2);
    }

    public function randomMemberCount(): int {
        return rand(1, $this->members);
    }

    public function getMembers(): int {
        return $this->members;
    }

    public function getMaxLen(): int {
        return $this->max_len;
    }

    public function getMaxPrefixLen(): int {
        return $this->max_prefix_len;
    }

    public function getRandomIndex(): int {
        return rand(0, $this->members - 1);
    }

    public function getRandomExpire(bool $millis = false): int {
        return rand($this->expire_min, $this->expire_max) * ($millis ? 1000 : 1);
    }

    public function getRandomExpireAt(bool $millis = false): int {
        return time() + $this->getRandomExpire($millis);
    }

    public function getRandomTimeout(): float {
        return $this->randFloatRange($this->timeout_min, $this->timeout_max);
    }

    public function getRandomTimeoutMs(): int {
        return (int)($this->getRandomTimeout() * 1000);
    }

    /* Convert a keyname in a deterministic way to a fuzzing key given
       ouc active configuration */
    public function toFuzzingKey(string $type, string $key): string {
        $h = intval(hexdec(hash('xxh64', $key)));

        return $this->getKey($type, $h % $this->shards, $h % $this->keys);
    }

    /** @return array{float|string, float|string} */
    public function randomScoreRange(): array {
        [$min, $max] = [$this->getRandomFloat(), $this->getRandomFloat()];

        if ($max < $min)
            [$max, $min] = [$min, $max];

        $rng = rand();

        if ($rng & 0b111)
            $min = '-inf';
        if ($rng & 0b111000)
            $max = '+inf';

        return [$min, $max];
    }

    /** @return int[] */
    public function randomRange(?int $maxlen = null): array {
        $maxlen ??= $this->members;

        if ($maxlen <= 0)
            return [0, -1];

        $direction = rand(0, 1) === 0 ? 'ltr' : 'rtl';
        $start = rand(0, $maxlen - 1);
        $end = rand(0, $maxlen - 1);

        if ($direction === 'ltr' && $start > $end) {
            $temp = $start;
            $start = $end;
            $end = $temp;
        }

        if ($direction === 'rtl') {
            $start = -($maxlen - $start);
            $end = -($maxlen - $end);

            if ($start < $end) {
                $temp = $start;
                $start = $end;
                $end = $temp;
            }
        }

        return [$start, $end];
    }

    /**
     * @return string[]
     */
    public static function randomLexRange(): array {
        return [self::randomLexArg(), self::randomLexArg()];
    }

    public static function randomLexArg(): string {
        $rng = rand();

        if ($rng & 1)
            return '-';
        else if ($rng & 2)
            return '+';

        $result = $rng & 4 ? '[' : '(';

        for ($i = 0; $i < 1 + ($rng % 4); $i++) {
            $result .= self::ALPHABET[rand(0, strlen(self::ALPHABET) - 1)];
        }

        return $result;
    }

    public function getRandomDB(): int {
        return rand(0, $this->databases - 1);
    }
}
