<?php

namespace Mgrunder\PhpredisCommandFuzzer;

abstract class ScriptArg {
    /* What we should put into our generated PHP scripts */
    abstract public function code(): string;

    /* The value we should actually use when executing at runtime */
    abstract public function value(): mixed;
}
