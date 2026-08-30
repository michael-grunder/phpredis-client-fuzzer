<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SetCardinalityCommand;

class sdiffcard extends SetCardinalityCommand {
    protected function supportsApprox(): bool {
        return false;
    }
}
