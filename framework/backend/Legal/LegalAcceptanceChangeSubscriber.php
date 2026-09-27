<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Legal\LegalAdminAudience;

/** Invalidates the section's cached histograms for local and mirrored acceptance changes. */
final class LegalAcceptanceChangeSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Source fact, including collection clears
     * @param SourceChangeProvenance $provenance Origin of the fact; both paths invalidate the cache
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$change->isRt() && $change->sourceKey === HilosDbContext::legalAcceptances) {
            LegalAdminAudience::markStale();
        }
    }
}
