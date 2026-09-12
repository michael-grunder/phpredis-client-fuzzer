<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;

return static function (HookRegistry $hooks, array $arguments): void {
    /** @var array<string, string> $arguments Supplied by HookLoader. */
    $hooks->deny('configured', 'ping', [], $arguments['reason'] ?? 'default');
};
