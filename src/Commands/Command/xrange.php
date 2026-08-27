<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\XRangeCommand;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

class xrange extends XRangeCommand {
    protected function randomIdRange(): array {
        return Events::instance()->randomIdRange();
    }
}
