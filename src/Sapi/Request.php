<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

use Mgrunder\RedisClientTester\Cli\ExitCode;

/** Bounded-memory HTTP capture with a wall-clock deadline, including partial replies. */
final class Request
{
    public function __construct(
        private readonly string $address,
        private readonly string $token,
        private readonly float $timeout,
    ) {
        if (preg_match('/\A127\.0\.0\.1:([0-9]+)\z/', $address, $match) !== 1
            || (int) $match[1] < 1 || (int) $match[1] > 65535
            || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
            || !is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('invalid local SAPI connection or timeout');
        }
    }

    /** @param (\Closure(): int) $signal */
    public function run(string $path, string $capture, \Closure $signal, bool $output = true): int
    {
        if (preg_match('~\A[a-zA-Z0-9/_.-]+\z~', $path) !== 1) {
            throw new \InvalidArgumentException('invalid request path');
        }
        $response = fopen($capture, 'x+');
        if ($response === false) {
            throw new \RuntimeException("cannot create response capture: {$capture}");
        }
        $socket = null;
        $deadline = microtime(true) + $this->timeout;
        try {
            $socket = @stream_socket_client('tcp://' . $this->address, $errno, $error, min(1.0, $this->timeout));
            if ($socket === false) {
                throw new \RuntimeException("HTTP connection failed: {$error}");
            }
            stream_set_blocking($socket, false);
            $request = "GET /{$path} HTTP/1.0\r\nHost: {$this->address}\r\nX-Fuzzer-Token: {$this->token}\r\nConnection: close\r\n\r\n";
            if (fwrite($socket, $request) !== strlen($request)) {
                throw new \RuntimeException('cannot send complete HTTP request');
            }
            while (!feof($socket)) {
                if (($received = $signal()) !== 0) {
                    return 128 + $received;
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('HTTP request timed out; partial response retained', ExitCode::TIMEOUT);
                }
                $read = [$socket];
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 20000) !== 1) {
                    continue;
                }
                if (stream_copy_to_stream($socket, $response, 65536) === false) {
                    throw new \RuntimeException('cannot capture HTTP response');
                }
            }
            rewind($response);
            $headers = '';
            while (($line = fgets($response, 8192)) !== false && $line !== "\r\n") {
                $headers .= $line;
                if (strlen($headers) > 65536) {
                    throw new \RuntimeException('oversized HTTP headers');
                }
            }
            if ($output) {
                fpassthru($response);
                echo "\n";
            }
            if ($line !== "\r\n"
                || preg_match('~\AHTTP/1\.[01] 200 ~', $headers) !== 1
                || !str_contains($headers, 'X-Fuzzer-Run: ' . $this->token . "\r\n")
                || preg_match('/^X-Fuzzer-Exit: (0|1|78)\r$/m', $headers, $match) !== 1) {
                throw new \RuntimeException('incomplete, foreign or failed HTTP response; see ' . $capture);
            }
            return (int) $match[1];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            fclose($response);
        }
    }
}
