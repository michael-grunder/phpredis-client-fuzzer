<?php

namespace Mgrunder\RedisClientTester\Commands;

abstract class SetMemberListCommand extends HashFieldListCommand {
    protected const MEMBER_TOKEN = 'MEMBERS';

    public function type(): string {
        return self::SET;
    }
}
