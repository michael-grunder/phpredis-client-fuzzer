<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

enum HookAction: string
{
    case Allow = 'allow';
    case Reject = 'reject';
    case Replace = 'replace';
}
