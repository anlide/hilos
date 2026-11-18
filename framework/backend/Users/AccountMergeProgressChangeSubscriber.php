<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\User as EntityUser;

/** Drops only derived merge observation, for local, applied and reverse rollback facts alike. */
final class AccountMergeProgressChangeSubscriber implements SourceMirrorSubscriberInterface
{
    /**
     * @param SourceChange $change Source mutation, including clears and rollback reversals
     * @param SourceChangeProvenance $provenance Local or applied origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$change->isRt() && self::affectsProgress($change->sourceKey, $change->mutationType, $change->row)) {
            AccountMergeProgressResolver::forgetAll();
        }
    }

    /**
     * @param string $sourceKey DB collection key
     * @param TableMutationType $mutationType Accepted mutation
     * @param array<string, mixed> $row Full row or changed columns at the source boundary
     * @return bool Whether this fact can change the saved projection or its account labels
     */
    public static function affectsProgress(string $sourceKey, TableMutationType $mutationType, array $row = []): bool
    {
        return match ($sourceKey) {
            HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
            HilosDbContext::accountOperationResults, HilosDbContext::userMerges => true,
            HilosDbContext::users => $mutationType !== TableMutationType::Update || array_key_exists(EntityUser::name, $row),
            default => false,
        };
    }
}
