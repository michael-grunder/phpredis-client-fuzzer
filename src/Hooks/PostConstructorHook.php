<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Hooks;

interface PostConstructorHook
{
    /** Runs once per client object, before the fuzzer uses it. */
    public function postConstructor(ClientEvent $event): void;
}
