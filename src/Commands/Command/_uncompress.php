<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\DecodeCommand;

class _uncompress extends DecodeCommand
{
    protected function encoder(): string
    {
        return '_compress';
    }
}
