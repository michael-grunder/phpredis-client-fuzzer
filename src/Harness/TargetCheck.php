<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\ping;

/** Read-only availability check for the harness's Redis port pool. */
final class TargetCheck
{
    public function __construct(private readonly ?\Redis $probe = null)
    {
    }

    /** @param list<int> $ports */
    public function check(CommandTemplate $template, array $ports): void
    {
        if (!$template->uses('port')) {
            return;
        }

        $failures = [];
        foreach ($ports as $port) {
            $values = [];
            $tokens = $template->render(['port' => $port]);
            foreach ($tokens as $index => $token) {
                foreach (['host', 'username', 'password'] as $name) {
                    if ($token === '--' . $name) {
                        $values[$name] = $tokens[$index + 1] ?? '';
                    } elseif (str_starts_with($token, '--' . $name . '=')) {
                        $values[$name] = substr($token, strlen($name) + 3);
                    }
                }
            }
            $host = $values['host'] ?? '127.0.0.1';
            $auth = isset($values['password'])
                ? ['auth' => isset($values['username'])
                    ? [$values['username'], $values['password']]
                    : [$values['password']]]
                : [];
            $probe = $this->probe ?? new \Redis();
            $connected = false;
            try {
                $connected = @$probe->connect($host, $port, 1.0, null, 0, 1.0, $auth);
                if (!$connected) {
                    throw new \RuntimeException('connection failed');
                }
                ping::beginInvocation();
                $errors = [];
                try {
                    $reply = (new ping())->exec($probe);
                } finally {
                    $errors = ping::finishInvocationRedisErrors();
                }
                if (!in_array($reply, [true, 'PONG', '+PONG'], true)) {
                    throw new \RuntimeException(implode('; ', $errors) ?: 'PING did not return PONG');
                }
            } catch (\Exception $error) {
                $failures[] = "  {$host}:{$port}: " . $error->getMessage();
            } finally {
                if ($connected) {
                    $probe->close();
                }
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(
                "Redis/Valkey startup check failed (1s connect/read timeout):\n"
                . implode("\n", $failures)
                . "\nStart the servers or correct the harness --port list and worker --host/auth options."
                . " No fuzzing jobs were started.",
            );
        }
    }
}
