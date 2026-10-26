<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\CommandStats;

enum NodeRole: string
{
    case Primary = 'primary';
    case Replica = 'replica';
}
