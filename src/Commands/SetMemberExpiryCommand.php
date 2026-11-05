<?php

namespace Mgrunder\RedisClientTester\Commands;

abstract class SetMemberExpiryCommand extends HashFieldExpiryCommand {
    protected const MEMBER_TOKEN = 'MEMBERS';

    public function type(): string {
        return self::SET;
    }
}
