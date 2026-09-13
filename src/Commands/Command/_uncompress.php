<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\DecodeCommand;

class _uncompress extends DecodeCommand
{
    protected function encoder(): string
    {
        return '_compress';
    }
}
