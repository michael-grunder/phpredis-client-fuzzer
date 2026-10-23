<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

/** Linux ASAN startup settings, applied only to the inferior process. */
final class AsanRuntime
{
    /** @param array<string, string> $environment */
    private function __construct(public readonly array $environment)
    {
    }

    public static function create(?string $runtime = null): self
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('the ASAN launcher currently requires Linux');
        }
        if ($runtime === null) {
            $output = [];
            $status = 0;
            exec('gcc -print-file-name=libasan.so 2>/dev/null', $output, $status);
            $runtime = $status === 0 ? trim(implode("\n", $output)) : '';
        }
        $path = realpath($runtime);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('ASAN runtime not found; pass --asan-runtime=/absolute/path/to/libasan.so');
        }
        if (preg_match('/[\s:]/', $path) === 1) {
            throw new \InvalidArgumentException('ASAN runtime path cannot contain whitespace or colons (LD_PRELOAD separators)');
        }

        $preload = getenv('LD_PRELOAD') ?: '';
        if (stripos($preload, 'valgrind') !== false) {
            throw new \InvalidArgumentException('ASAN and Valgrind must run separately');
        }

        $environment = [
            'LD_PRELOAD' => $path . ($preload === '' ? '' : ' ' . $preload),
            'USE_ZEND_ALLOC' => '0',
            'USE_TRACKED_ALLOC' => '1',
            'ZEND_DONT_UNLOAD_MODULES' => '1',
            // Always keep reports in captured stderr, even with custom options.
            'ASAN_OPTIONS' => 'detect_leaks=0:' . (getenv('ASAN_OPTIONS') ?: '') . ':halt_on_error=1:log_path=stderr',
            'UBSAN_OPTIONS' => (getenv('UBSAN_OPTIONS') ?: '') . ':halt_on_error=1:print_stacktrace=1:log_path=stderr',
            'LSAN_OPTIONS' => (getenv('LSAN_OPTIONS') ?: '') . ':log_path=stderr',
        ];
        foreach (['ASAN_SYMBOLIZER_PATH', 'PHP_INI_SCAN_DIR', 'PHPRC', 'LD_LIBRARY_PATH'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return new self($environment);
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    public function command(array $argv): array
    {
        if (preg_match('/(?:valgrind|^rr$)/i', basename($argv[0] ?? '')) === 1) {
            throw new \InvalidArgumentException('ASAN requires a PHP binary, without Valgrind or rr');
        }
        $prefix = ['/usr/bin/env'];
        foreach ($this->environment as $name => $value) {
            $prefix[] = $name . '=' . $value;
        }

        return [...$prefix, ...$argv];
    }
}
