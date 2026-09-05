<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Hooks;

interface PreDestructorHook
{
    /**
     * Runs when the fuzzer is finished with a client object. The client is
     * still fully usable; PHP has not torn it down.
     */
    public function preDestructor(ClientEvent $event): void;
}
