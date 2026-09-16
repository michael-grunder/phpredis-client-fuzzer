<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\BlockingListPopCommand;

class brpop extends BlockingListPopCommand {
    public function flags(): int {
        return parent::flags() | self::COMMON;
    }
}
