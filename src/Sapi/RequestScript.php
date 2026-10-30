<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

/** The same workload and concrete call logging in burst and long-lived requests. */
final class RequestScript
{
    /** Remove only the files owned by a successfully completed request. */
    public static function discard(string $script): void
    {
        // Remove the executable first, so dropping the claim cannot make it replayable.
        foreach ([$script, substr($script, 0, -4) . '.calls.php', $script . '.http', $script . '.executed'] as $path) {
            if ((file_exists($path) || is_link($path)) && !unlink($path)) {
                throw new \RuntimeException("cannot remove successful request artifact: {$path}");
            }
        }
    }

    /** @param list<string> $arguments */
    public static function source(string $autoload, array $arguments, string $name): string
    {
        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export(realpath($autoload), true) . ";\n"
            . '$arguments = ' . var_export($arguments, true) . ";\n"
            . '$arguments[] = "--script-log=" . __DIR__ . ' . var_export('/' . $name . '.calls.php', true) . ";\n"
            . '$output = fopen("php://output", "w");' . "\n"
            . '$error = fopen("php://stderr", "w");' . "\n"
            . "ob_start();\n"
            . '$code = (new \\Mgrunder\\RedisClientTester\\Cli\\Application($output, $error))->run($arguments);' . "\n"
            . 'header("X-Fuzzer-Exit: " . $code);' . "\n"
            . "ob_end_flush();\n";
    }
}
