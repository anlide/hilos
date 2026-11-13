<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * A project's owner from its {@see AccountDataOwnerDirectory}, taken into the account merge.
 *
 * Expected for the loser of every merge once the owner is registered for the merge — declared one
 * for everybody, its agent type registered without an index, and the registered class moving its
 * part ({@see AccountMergeProjectOwner}) — rows or not, because only the owner knows what it holds.
 * Otherwise the coordinator waits while the loser still has rows and expects nothing once it has
 * none. A per-person owner is never registered for the merge: a row moving from the loser's set to
 * the survivor's is a write across two sets, the whole-table owner's write. Whether the class also
 * erases its part has no bearing here.
 * SCAFFOLD: no live caller until the merge coordinator opens its operation (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
final readonly class AccountMergeProjectParticipant
{
    /**
     * @param AccountDataOwner $owner Owner as the project declared it
     */
    public function __construct(private AccountDataOwner $owner)
    {
    }

    /** @return string Participant key, the owner's key */
    public function key(): string
    {
        return $this->owner->key;
    }

    /** @return list<string> Collection keys of the project context the owner covers */
    public function collections(): array
    {
        return $this->owner->collections;
    }

    /**
     * @param int $loserUserId Loser of the merge, the only account the merge asks about
     * @return list<AccountOperationExpectation> The owner, a wait for remaining rows, or nothing
     * @throws EmptyValueException When the owner type is empty
     * @throws ValueTooLongException When the owner type exceeds the address limit
     * @throws HilosException When the row question fails
     */
    public function expectationsFor(int $loserUserId): array
    {
        if ($this->isOwnerRegistered()) {
            return [new AccountOperationExpectation(
                $this->owner->key,
                $loserUserId,
                new AccountOperationOwnerAddress($this->owner->ownerType, null),
            )];
        }
        if (($this->owner->hasRowsOf)($loserUserId)) {
            return [new AccountOperationExpectation($this->owner->key, $loserUserId, null)];
        }

        return [];
    }

    /** @return bool Whether the owner is one for everybody, registered without an index, and its class moves its part */
    private function isOwnerRegistered(): bool
    {
        if ($this->owner->perPerson) {
            return false;
        }
        $entry = Hilos::appClass()::AGENTS[$this->owner->ownerType] ?? null;
        if ($entry === null || AgentRegistry::requiresIndex($entry)) {
            return false;
        }
        $worker = AgentRegistry::workerClass($entry);

        return $worker !== null && is_a($worker, AccountMergeProjectOwner::class, true);
    }
}
