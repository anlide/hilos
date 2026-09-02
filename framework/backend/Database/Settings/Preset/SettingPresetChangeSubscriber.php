<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Log\LogWriteLevelSubscriber;
use Hilos\Pages\AbstractHilosSettingPresetsPage;

/**
 * Tells the preset pages that a settings row moved, wherever in the cluster it moved (HIL-762).
 *
 * This is what makes a value edited on the general settings screen appear as a difference on the
 * cards without anybody polling for it: the row is written in one process, the change travels the
 * sync every process already listens to, and its arrival is announced on the source bus. The
 * subscriber turns that announcement into a rebuild owed by the pages that have an audience.
 *
 * Create, update and delete are one event here. Writing a row overrides the environment, editing
 * it changes the override, removing it hands the key back — three facts, one question: does the
 * applied preset still describe what the settings say.
 *
 * The key in the event is deliberately not examined, unlike in {@see LogWriteLevelSubscriber},
 * which can afford to. An update carries only the columns that changed and so names no key at all,
 * and a preset group covers several keys rather than one — so the narrowing would have to guess
 * where the neighbour merely reads. Marking one page too often costs a rebuild in memory that a
 * fingerprint comparison then throws away.
 *
 * Provenance is ignored for the reason {@see ViewCacheSubscriber} ignores it: the cards are equally
 * out of date whether this process wrote the row or applied somebody else's write.
 */
final class SettingPresetChangeSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * Marks the preset pages stale when the settings collection moved.
     *
     * Any other collection is a silent no-op: this sits on a bus every mutation of every
     * collection passes through, so narrowing is its first job.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Ignored, see the class docblock
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt() || $change->sourceKey !== HilosDbContext::settings) {
            return;
        }

        AbstractHilosSettingPresetsPage::markStale();
    }
}
