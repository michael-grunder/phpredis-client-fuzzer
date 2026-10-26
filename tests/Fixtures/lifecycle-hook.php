<?php

declare(strict_types=1);

use Mgrunder\RedisClientTester\Hooks\ClientEvent;
use Mgrunder\RedisClientTester\Hooks\CompletedInvocation;
use Mgrunder\RedisClientTester\Hooks\PostCommandHook;
use Mgrunder\RedisClientTester\Hooks\PostConstructorHook;

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
