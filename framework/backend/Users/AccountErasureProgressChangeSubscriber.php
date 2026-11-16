<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Users\AccountStandingAudience;

/** Drops this process's derived erasure progress at every change of its DB facts. */
final class AccountErasureProgressChangeSubscriber implements SourceMirrorSubscriberInterface
{
    /**
     * @param SourceChange $change Source fact, including a cleared collection
     * @param SourceChangeProvenance $provenance Local or mirrored origin; both invalidate the same memory
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt()) {
            return;
        }

        switch ($change->sourceKey) {
            case HilosDbContext::users:
                if (in_array($change->mutationType, [TableMutationType::Delete, TableMutationType::Clear], true)) {
                    AccountStandingAudience::forgetErasureFilter();
                }
                break;
            case HilosDbContext::accountOperations:
            case HilosDbContext::accountOperationAssignments:
            case HilosDbContext::accountOperationResults:
            case HilosDbContext::accountDeletions:
                AccountErasureProgressResolver::forgetAll();
                break;
        }
    }
}
