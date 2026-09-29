<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Entity\Item\LegalAcceptance as EntityLegalAcceptance;
use Hilos\Database\Entity\Item\User as EntityUser;

/**
 * Drops the remembered standing of a person when a fact it was composed from changes (HIL-945).
 *
 * The three sources are read process-wide, so their writes reach this subscriber in every process,
 * whichever process made them. Nobody who writes a block, a deletion request or an acceptance has
 * to remember whom to tell: the write is the announcement.
 *
 * A person's row is written far more often than its block changes - the last activity moves on
 * every visit - so only a change that carries the block drops the verdict; otherwise the memory
 * would be emptied faster than the guard reads it. A deletion request's update carries only the
 * columns that moved, and those do not name the person; such a change, and a cleared table, drop
 * every verdict instead of guessing whose it was.
 */
final class AccountStandingChangeSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Source fact, including collection clears
     * @param SourceChangeProvenance $provenance Origin of the fact; a local write and a mirrored one drop the same verdict
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt()) {
            return;
        }

        switch ($change->sourceKey) {
            case HilosDbContext::users:
                if ($change->mutationType !== TableMutationType::Update || array_key_exists(EntityUser::block, $change->row)) {
                    $this->forget($change, $change->sourceId === '' ? null : (int) $change->sourceId);
                }
                break;
            case HilosDbContext::accountDeletions:
                $this->forget($change, $this->userIdOf($change, EntityAccountDeletion::user_id));
                break;
            case HilosDbContext::legalAcceptances:
                $this->forget($change, $this->userIdOf($change, EntityLegalAcceptance::user_id));
                break;
        }
    }

    /**
     * @param SourceChange $change Change of a row that names its person in one column
     * @param string $column The column naming the person
     * @return ?int The person the row belongs to, or null when the change does not say
     */
    private function userIdOf(SourceChange $change, string $column): ?int
    {
        $userId = $change->row[$column] ?? $change->previous[$column] ?? null;

        return $userId === null ? null : (int) $userId;
    }

    /**
     * @param SourceChange $change The change heard
     * @param ?int $userId Person the change is about, or null when it does not say
     */
    private function forget(SourceChange $change, ?int $userId): void
    {
        if ($change->mutationType === TableMutationType::Clear || $userId === null) {
            AccountStandingResolver::forgetAll();

            return;
        }

        AccountStandingResolver::forget($userId);
    }
}
