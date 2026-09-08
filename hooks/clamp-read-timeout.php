<?php

declare(strict_types=1);

/**
 * Invocation hook: keep the fuzzer from disabling its own read timeout.
 *
 * `setoption` treats `Redis::OPT_READ_TIMEOUT` like any other option and
 * generates huge floats, huge integers, strings, null and false for it. Both
 * extensions accept those: Relay clamps a huge value to 4294967295 seconds and
 * turns a non-numeric one into 0.0, PhpRedis stores the raw double, and 0 means
 * "wait forever". One such step early in a run leaves every later reply
 * unbounded, so a server that never answers (a MULTI or pipeline containing a
 * failing two-argument AUTH, for example) hangs until the harness
 * `--run-timeout` fires instead of raising after `--read-timeout` seconds.
 *
 * This hook rewrites only the values that would leave the socket unbounded, so
 * the option is still exercised. Anything already inside the band passes
 * through untouched, including the odd types that coerce into it (`true`,
 * `"0.75"`). The band defaults to the sane range `setoption` itself uses and
 * can be moved with FUZZ_MIN_READ_TIMEOUT / FUZZ_MAX_READ_TIMEOUT; set
 * FUZZ_READ_TIMEOUT_CLAMP_VERBOSE=1 to log every rewrite to stderr.
 *
 * Load it alongside the workload's own timeout flags:
 *
 *     bin/phpredis-fuzz --include=local --read-timeout=1.0 \
 *         --hook=hooks/clamp-read-timeout.php
 *
 * Relay\Cluster::OPT_NODE_READ_TIMEOUT is deliberately not covered: the catalog
 * already generates only sane values for it.
 */

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

return static function (HookRegistry $hooks): void {
    /* PhpRedis and Relay have always numbered this option the same, but read
       both constants so a build that renumbers one of them still matches. */
    $options = [];
    foreach (['Redis::OPT_READ_TIMEOUT', 'Relay\\Relay::OPT_READ_TIMEOUT'] as $constant) {
        if (!defined($constant)) {
            continue;
        }
        $value = constant($constant);
        if (is_int($value) && !in_array($value, $options, true)) {
            $options[] = $value;
        }
    }

    if ($options === []) {
        return;
    }

    $bound = static function (string $variable, float $fallback): float {
        $value = getenv($variable);
        if (!is_string($value) || !is_numeric($value)) {
            return $fallback;
        }
        $seconds = (float) $value;

        return $seconds > 0.0 && is_finite($seconds) ? $seconds : $fallback;
    };

    $minimum = $bound('FUZZ_MIN_READ_TIMEOUT', 0.0005);
    $maximum = $bound('FUZZ_MAX_READ_TIMEOUT', 1.5);
    if ($minimum > $maximum) {
        [$minimum, $maximum] = [$maximum, $minimum];
    }

    $hooks->add(
        'clamp-read-timeout',
        new readonly class($options, $minimum, $maximum, getenv('FUZZ_READ_TIMEOUT_CLAMP_VERBOSE') !== false)
            implements InvocationHook {
            /** @param list<int> $options */
            public function __construct(
                private array $options,
                private float $minimum,
                private float $maximum,
                private bool $verbose,
            ) {
            }

            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                if (strcasecmp($invocation->method, 'setOption') !== 0
                    || count($invocation->arguments) < 2
                    || !in_array($this->integerValue($invocation->arguments[0]), $this->options, true)) {
                    return HookDecision::allow();
                }

                $requested = $invocation->arguments[1];
                $seconds = $this->effectiveSeconds($requested);
                if ($seconds >= $this->minimum && $seconds <= $this->maximum) {
                    return HookDecision::allow();
                }

                /* Everything the extensions read as zero, negative, infinite or
                   simply enormous means "no timeout", so those become the upper
                   bound rather than the lower one. */
                $clamped = $seconds > 0.0 && $seconds < $this->minimum
                    ? $this->minimum
                    : $this->maximum;

                if ($this->verbose) {
                    fwrite(STDERR, sprintf(
                        "clamp-read-timeout: %s setOption(OPT_READ_TIMEOUT, %s) -> %s\n",
                        $invocation->client::class,
                        $this->describe($requested),
                        var_export($clamped, true),
                    ));
                }

                $arguments = $invocation->arguments;
                $arguments[1] = $clamped;

                return HookDecision::replace(array_values($arguments));
            }

            /** The seconds PhpRedis and Relay would actually install for a value. */
            private function effectiveSeconds(mixed $value): float
            {
                $seconds = match (true) {
                    is_int($value), is_float($value) => (float) $value,
                    is_bool($value) => $value ? 1.0 : 0.0,
                    is_string($value) => is_numeric($value) ? (float) $value : 0.0,
                    /* null, arrays and objects: either rejected by the option
                       setter or read as zero. Treat them as unbounded. */
                    default => 0.0,
                };

                return is_finite($seconds) ? $seconds : INF;
            }

            /** Match the integer conversion used by PhpRedis and Relay options. */
            private function integerValue(mixed $value): int
            {
                // Standard PHP objects convert to 1 with a warning. Avoid
                // producing that warning in the hook itself.
                return is_object($value) ? 1 : (int) $value;
            }

            private function describe(mixed $value): string
            {
                return match (true) {
                    is_scalar($value), $value === null => var_export($value, true),
                    is_array($value) => 'array(' . count($value) . ')',
                    is_object($value) => $value::class,
                    default => get_debug_type($value),
                };
            }
        },
    );
};
