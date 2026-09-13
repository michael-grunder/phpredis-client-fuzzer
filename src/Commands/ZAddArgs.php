<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\ScriptArg;

/** A bounded corpus; names describe inputs, not an assumed client outcome. */
final class ZAddArgs
{
    public const VALID_VARIANTS = [
        'plain', 'empty-options', 'nx-missing', 'nx-present', 'xx-missing',
        'xx-present', 'lt-lower', 'lt-equal', 'lt-higher', 'gt-lower', 'gt-equal',
        'gt-higher', 'xx-lt', 'xx-gt', 'ch-new', 'ch-changed', 'ch-unchanged',
        'incr', 'incr-nx-missing', 'incr-nx-present', 'incr-xx-missing',
        'incr-xx-present', 'incr-gt', 'incr-lt', 'duplicate-member', 'equal-scores',
        'mixed-case', 'reordered-options', 'associative-options',
        'score-zero', 'score-negative-zero', 'score-numeric-string',
        'score-positive-infinity', 'score-negative-infinity',
    ];
    public const EDGE_VARIANTS = [
        'null-options', 'nx-xx-conflict', 'lt-gt-conflict', 'nx-gt-invalid',
        'nx-lt-invalid', 'incr-many-invalid', 'nested-options', 'referenced-options', 'score-nan',
    ];

    public readonly string $variant;
    public readonly string $member;
    public readonly string $otherMember;
    public readonly ?float $initialScore;
    /** @var list<float|string> */
    private array $pairs;
    /** @var array<int|string, mixed>|null */
    private ?array $options;

    public function __construct(FuzzConfig $config, ?string $variant = null)
    {
        if ($variant === null) {
            // Keep successful reply conversion much more frequent than parser edge cases.
            $variants = rand(0, 7) === 0 ? self::EDGE_VARIANTS : self::VALID_VARIANTS;
            $variant = $variants[array_rand($variants)];
        }
        if (!in_array($variant, [...self::VALID_VARIANTS, ...self::EDGE_VARIANTS], true)) {
            throw new \InvalidArgumentException("Unknown ZADD variant: {$variant}");
        }
        $this->variant = $variant;
        $this->member = $config->getRandomMember(Command::ZSET);
        $this->otherMember = $this->member . ':other';
        $this->initialScore = in_array($variant, [
            'nx-missing', 'xx-missing', 'ch-new', 'incr-nx-missing', 'incr-xx-missing',
        ], true) ? null : 10.0;
        $this->options = match ($variant) {
            'plain', 'null-options' => null,
            'empty-options', 'duplicate-member', 'equal-scores', 'score-zero',
            'score-negative-zero', 'score-numeric-string', 'score-positive-infinity',
            'score-negative-infinity', 'score-nan' => [],
            'nx-missing', 'nx-present' => ['NX'],
            'xx-missing', 'xx-present' => ['XX'],
            'lt-lower', 'lt-equal', 'lt-higher' => ['LT', 'CH'],
            'gt-lower', 'gt-equal', 'gt-higher' => ['GT', 'CH'],
            'xx-lt' => ['XX', 'LT', 'CH'],
            'xx-gt' => ['XX', 'GT', 'CH'],
            'ch-new', 'ch-changed', 'ch-unchanged' => ['CH'],
            'incr', 'incr-many-invalid' => ['INCR'],
            'incr-nx-missing', 'incr-nx-present' => ['NX', 'INCR'],
            'incr-xx-missing', 'incr-xx-present' => ['XX', 'INCR'],
            'incr-gt' => ['XX', 'GT', 'CH', 'INCR'],
            'incr-lt' => ['XX', 'LT', 'CH', 'INCR'],
            'mixed-case' => ['xX', 'gT', 'cH'],
            'reordered-options' => ['CH', 'GT', 'XX'],
            'associative-options' => ['condition' => 'XX', 7 => 'GT', 'changed' => 'CH'],
            'nx-xx-conflict' => ['NX', 'XX'],
            'lt-gt-conflict' => ['LT', 'GT'],
            'nx-gt-invalid' => ['NX', 'GT'],
            'nx-lt-invalid' => ['NX', 'LT'],
            'nested-options' => [['NX'], 'CH' => ['unexpected' => true]],
            'referenced-options' => ['CH', 'CH'],
        };
        $score = match ($variant) {
            'lt-lower', 'gt-lower', 'xx-lt' => 9.0,
            'lt-equal', 'gt-equal', 'ch-unchanged' => 10.0,
            'score-zero' => 0.0,
            'score-negative-zero' => -0.0,
            'score-numeric-string' => '1.25e1',
            'score-positive-infinity' => INF,
            'score-negative-infinity' => -INF,
            'score-nan' => NAN,
            'incr-lt' => -1.5,
            'incr', 'incr-nx-missing', 'incr-nx-present', 'incr-xx-missing',
            'incr-xx-present', 'incr-gt' => 1.5,
            default => 11.0,
        };
        $this->pairs = [$score, $this->member];
        if ($variant === 'duplicate-member') {
            $this->pairs = [10.0, $this->member, 11.0, $this->member];
        } elseif ($variant === 'equal-scores' || $variant === 'incr-many-invalid') {
            $this->pairs = [$score, $this->member, $score, $this->otherMember];
        } elseif ($variant === 'plain') {
            $this->pairs = [];
            $count = rand(1, $config->getMembers());
            for ($i = 0; $i < $count; $i++) {
                $this->pairs[] = $config->getRandomFloat();
                $this->pairs[] = $config->getRandomMember(Command::ZSET);
            }
        }
    }

    /** @return list<mixed> Arguments after the key. */
    public function arguments(bool $raw): array
    {
        if ($raw) {
            // PHP-only malformed structures have no protocol equivalent. Keep
            // their raw counterparts explicitly invalid rather than stringifying arrays.
            $options = in_array($this->variant, ['null-options', 'nested-options'], true)
                ? ['INVALID-OPTION'] : array_values($this->options ?? []);

            return [...$options, ...$this->pairs];
        }
        if ($this->variant === 'plain') {
            return $this->pairs;
        }
        if ($this->variant === 'referenced-options') {
            $options = new class extends ScriptArg {
                /** @return array{string, string} */
                public function value(): array
                {
                    $option = 'CH';

                    return [&$option, &$option];
                }

                public function code(): string
                {
                    return '(static function () { $option = "CH"; return [&$option, &$option]; })()';
                }
            };
        } else {
            $options = $this->options;
        }

        return [$options, ...$this->pairs];
    }
}
