<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

/**
 * How generated keys for a single fuzz step are distributed over cluster hash
 * slots.
 *
 * Generated cluster keys embed a hash tag (`string:{7}:42`), so the tag alone
 * decides the slot. This enum records which tag policy FuzzConfig is applying
 * while a command builds its arguments.
 */
enum SlotPolicy: string
{
    /**
     * Every generated key shares one hash tag, so a multi-key command stays
     * inside a single slot. This is the default for commands Redis itself
     * requires to be single-slot.
     */
    case SameSlot = 'same-slot';

    /**
     * Generated keys deliberately rotate through distinct hash tags so a
     * single-slot command is guaranteed to produce a `CROSSSLOT` error. Chosen
     * with `crossSlotChance` probability, and only when fuzzing a cluster.
     */
    case CrossSlot = 'cross-slot';

    /**
     * Each generated key picks its own random hash tag. Used for commands the
     * clients split across nodes themselves (`Command::CROSSSLOT`), where a
     * mixed-slot key set is the interesting input rather than an error.
     */
    case Unconstrained = 'unconstrained';
}
