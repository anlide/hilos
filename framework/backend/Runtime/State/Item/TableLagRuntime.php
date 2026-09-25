<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;

/**
 * TableLagRuntime - the singleton runtime state of the test-only table lag (HIL-1020).
 *
 * Two artificial delays a test puts on a browser table, one per frame it wants to watch arrive
 * late: the window that answers a changed viewport, and the counts beside the filter options.
 * The row skeleton and the counts are both decided by how long those frames take, and on a
 * development box they take tens of milliseconds - too fast for either state to be seen. This
 * row is what makes them visible, to an e2e and to the eye alike.
 *
 * Framework-owned and mounted for every project, like {@see ProtectedModeRuntime}, and written
 * the same way: by the daemon master of the node, which answers `test:table:lag` itself. The row
 * is node-local and never travels the mesh. On production it stays at zero for good - the command
 * socket refuses every `test:` command there, so nothing can write it.
 */
final class TableLagRuntime extends RtState
{
    /** Runtime item alias the framework mounts and the RT sync carries. */
    public const string RT_ITEM = 'hilosTableLagRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string windowMs = 'windowMs';
    public const string facetsMs = 'facetsMs';

    /** Milliseconds an incoming table viewport change is held before it is answered; 0 means no lag. */
    public int $windowMs = 0;

    /** Milliseconds a facet count is held before it is counted and sent; 0 means no lag. */
    public int $facetsMs = 0;

    /**
     * Creates the singleton row with both lags off.
     *
     * @return static Table lag runtime state with no lag
     */
    public static function create(): static
    {
        $instance = new static();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Runtime singleton restored from a sync row
     * @throws InvalidFormatException When the row lost either lag or carries one as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->windowMs = self::requireInt($row, self::windowMs);
        $instance->facetsMs = self::requireInt($row, self::facetsMs);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The master writes the row and the workers serve the tables, so without the diffs no worker
     * would ever hold a frame back.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a lag as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->windowMs = self::patchInt($diff, self::windowMs, $this->windowMs);
        $this->facetsMs = self::patchInt($diff, self::facetsMs, $this->facetsMs);
    }

    /**
     * @return string Runtime collection key for the table lag singleton
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_ITEM;
    }

    /**
     * @return string Stable singleton row id
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::windowMs => $this->windowMs,
            self::facetsMs => $this->facetsMs,
        ];
    }
}
