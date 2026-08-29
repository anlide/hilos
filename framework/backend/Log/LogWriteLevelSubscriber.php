<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting;

/**
 * Re-reads the write level when a settings row that could carry it moves (HIL-761).
 *
 * This is what makes an administrator's edit reach a running process without a restart and
 * without anybody polling: the row is written in one process of the cluster, the change travels
 * the sync every process already listens to, and its arrival is announced on the source bus like
 * any other. The subscriber turns that announcement back into a threshold.
 *
 * Create, update and delete are all the same event here. Writing the row overrides the
 * environment, editing it changes the override, and removing it hands the key back to the
 * environment - three different facts, one question: what does the level read as now.
 *
 * Provenance is ignored for the same reason {@see ViewCacheSubscriber} ignores it: the level in
 * force is equally wrong whether this process wrote the row or applied somebody else's write.
 */
final class LogWriteLevelSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * Re-resolves the write level when the change could have touched the row that carries it.
     *
     * Any collection other than the settings is a silent no-op: this subscriber sits on a bus
     * every mutation of every collection passes through, so narrowing is its first job.
     *
     * Within the settings, the narrowing goes as far as the fact allows and no further. A create
     * or a delete carries the whole row, so a row for some other key is dropped here. An update
     * carries only the columns that changed, and editing a setting changes its value alone - so
     * an updated row names no key, and the level is re-read rather than guessed at. Re-reading it
     * for somebody else's setting costs one settings lookup and stays silent, while skipping it
     * for our own would leave the process logging at a level nobody asked for.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Ignored, see the class docblock
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt() || $change->sourceKey !== HilosDbContext::settings) {
            return;
        }

        $key = $change->row[Setting::key] ?? null;
        if ($key !== null && $key !== LogSettingsCatalog::WRITE_LEVEL) {
            return;
        }

        LogWriteLevelApplier::applyFromSettings();
    }
}
