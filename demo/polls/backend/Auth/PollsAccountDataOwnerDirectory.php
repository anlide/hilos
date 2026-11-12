<?php

declare(strict_types=1);

namespace Demo\Polls\Auth;

use Demo\Polls\Database\PollsDbContext;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;

/**
 * PollsAccountDataOwnerDirectory - whose the polls demo's own collections are.
 *
 * The demo owns no personal rows of its own: its one collection, the guest names, belongs to
 * sessions without an account and has no column pointing at a person, so the erasure leaves it
 * alone.
 */
final class PollsAccountDataOwnerDirectory extends AccountDataOwnerDirectory
{
    /** @return array<string, AccountErasureExemption> The guest names, held apart from any account */
    protected static function exempt(): array
    {
        return [PollsDbContext::guests => AccountErasureExemption::OutsideAccount];
    }
}
