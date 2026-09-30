<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Legal\LegalAdminAudience;

/**
 * Invalidates the section's cached histograms for local and mirrored acceptance changes.
 *
 * A mirror rather than a reaction: the staleness it marks is this process's own memory, so it is
 * told at the write and not at the commit. A histogram rebuilt for a write that then rolls back
 * costs one rebuild and nothing else.
 */
final class LegalAcceptanceChangeSubscriber implements SourceMirrorSubscriberInterface
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
