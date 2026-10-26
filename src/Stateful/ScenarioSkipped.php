<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Stateful;

/**
 * Thrown by a scenario when the selected client cannot express the sequence
 * the scenario needs. It is a structured `skipped` outcome, never a failure.
 */
final class ScenarioSkipped extends \RuntimeException
{
}
