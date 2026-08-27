<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\XRangeCommand;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

class xrevrange extends XRangeCommand {
    protected function randomIdRange(): array {
        return array_reverse(Events::instance()->randomIdRange());
    }
}
