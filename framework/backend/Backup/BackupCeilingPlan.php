<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Runtime\View\Item\BackupHistory;

/**
 * BackupCeilingPlan - the outcome of planning the ceiling pass.
 *
 * Carries what the pass takes and what held the rest, so the agent can say why the store still
 * exceeds its ceiling instead of listing the guards that might have applied. Both halves are
 * empty when the ceiling never came into play.
 */
final class BackupCeilingPlan
{
    /**
     * @param list<BackupHistory> $doomed Rows to prune, oldest first
     * @param list<BackupCeilingSpare> $spared What held the rest, heaviest first
     */
    public function __construct(
        public readonly array $doomed,
        public readonly array $spared,
    ) {
    }
}
