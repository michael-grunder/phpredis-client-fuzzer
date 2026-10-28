<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Harness;

/** @internal Unwinds capture and reduction at a scheduler heartbeat, never in a signal handler. */
final class ShutdownRequested extends \RuntimeException
{
}
