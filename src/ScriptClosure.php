<?php

namespace Mgrunder\PhpredisCommandFuzzer;

class ScriptClosure extends ScriptArg {
    private \Closure $closure;
    private string $code;

    public function __construct(callable $closure, string $code) {
        $this->closure = \Closure::fromCallable($closure);
        $this->code = $code;
    }

    public function code(): string {
        return $this->code;
    }

    public function value(): callable {
        return $this->closure;
    }
}
