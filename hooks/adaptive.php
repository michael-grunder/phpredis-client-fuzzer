<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostConstructorHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\ScriptSetupHook;
use Mgrunder\PhpredisCommandFuzzer\InvocationRecorder;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\StrictClientInvoker;
use Relay\Cluster;
use Relay\Relay;

/**
 * Enable Relay's adaptive cache after connection (postConstructor).
 * --hook=hooks/adaptive.php --hook-arg=adaptive.width=12288
 * --hook-arg=adaptive.depth=6 --hook-arg=adaptive.minEvents=10
 * --hook-arg=adaptive.minRatio=5 --hook-arg=adaptive.formula=scaled
 * --hook-arg=adaptive.enabled=true
 * See README.md for bounds, defaults and shared-sketch lifetime.
 *
 * @param array<string, string> $arguments
 */
return static function (HookRegistry $hooks, array $arguments = []): void {
    $hooks->addLifecycleHook('adaptive', new class($arguments) implements PostConstructorHook, ScriptSetupHook {
        /** @var array<string, int|float|bool|string> */
        private readonly array $options;

        /** @var \WeakMap<Relay|Cluster, int> */
        private \WeakMap $clients;

        /** @param array<string, string> $arguments */
        public function __construct(array $arguments = [])
        {
            // Bounds and option names from relaySetAdaptiveCacheOptions() and cms.h.
            $options = ['width' => 12 * 1024, 'depth' => 6, 'enabled' => true];
            foreach ($arguments as $key => $value) {
                $options[$key] = match ($key) {
                    'width' => self::integer($key, $value, 512, 2147483647),
                    'depth' => self::integer($key, $value, 1, 8),
                    'minEvents' => self::integer($key, $value, -2147483648, 2147483647),
                    'minRatio' => self::ratio($value),
                    'formula' => match (strtolower($value)) {
                        'pure', 'scaled' => strtolower($value),
                        default => throw new \InvalidArgumentException('adaptive.formula must be pure or scaled'),
                    },
                    'enabled' => match (strtolower($value)) {
                        '1', 'true' => true,
                        '0', 'false' => false,
                        default => throw new \InvalidArgumentException('adaptive.enabled must be true, false, 1 or 0'),
                    },
                    default => throw new \InvalidArgumentException("Unknown adaptive hook argument: {$key}"),
                };
            }
            $this->options = $options;
            $this->clients = new \WeakMap();
        }

        public function postConstructor(ClientEvent $event): void
        {
            $client = $event->client;
            if (!$client instanceof Relay && !$client instanceof Cluster) {
                return;
            }
            $constant = Relay::class . '::OPT_ADAPTIVE_CACHE';
            if (!defined($constant)) {
                throw new \RuntimeException('The adaptive hook requires Relay::OPT_ADAPTIVE_CACHE support');
            }
            $option = Relay::OPT_ADAPTIVE_CACHE;
            $result = InvocationRecorder::invoke(
                new StrictClientInvoker(), $client, 'setOption', [$option, $this->options],
                'hook-setup', 'adaptive',
            );
            if ($result !== true) {
                throw new \RuntimeException($client::class . ' rejected OPT_ADAPTIVE_CACHE');
            }
            $this->clients[$client] = $option;
        }

        public function recordScriptSetup(): void
        {
            // ClientFactory applies the hook before Fuzzer opens the script log.
            foreach ($this->clients as $client => $option) {
                ScriptLogger::log($client, 'setOption', [$option, $this->options]);
            }
        }

        private static function integer(string $key, string $value, int $minimum, int $maximum): int
        {
            $integer = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => $minimum, 'max_range' => $maximum],
            ]);
            if (!is_int($integer)) {
                throw new \InvalidArgumentException("adaptive.{$key} must be an integer in {$minimum}..{$maximum}");
            }

            return $integer;
        }

        private static function ratio(string $value): float
        {
            if (!is_numeric($value) || !is_finite($ratio = (float) $value) || $ratio < 0) {
                throw new \InvalidArgumentException('adaptive.minRatio must be a finite number >= 0');
            }

            return $ratio;
        }
    });
};
