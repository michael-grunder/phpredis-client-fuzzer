<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\SetCardinalityCommand;

class sunioncard extends SetCardinalityCommand {
    protected function supportsApprox(): bool {
        return true;
    }
}
