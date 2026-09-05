<?php

declare(strict_types=1);

use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostCommandHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostConstructorHook;

return new class implements PostConstructorHook, PostCommandHook {
    /** @var list<string> */
    public array $seen = [];

    public function postConstructor(ClientEvent $event): void
    {
        $this->seen[] = 'constructed:' . $event->client::class;
    }

    public function postCommand(CompletedInvocation $invocation): void
    {
        $this->seen[] = 'command:' . $invocation->command;
    }
};
