<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

enum NodeRole: string
{
    case Primary = 'primary';
    case Replica = 'replica';
}
