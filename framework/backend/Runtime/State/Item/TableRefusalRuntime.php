<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;

/**
 * TableRefusalRuntime - the singleton runtime state of the test-only table refusal (HIL-1131).
 *
 * One table a test wants to see refused: every window of it fails to build, and the reader gets
 * "List unavailable" in place of the rows while the neighbours on the page keep theirs. A stand
 * has no honest way to make a window fail, so this row is what makes the refused state visible,
 * to an e2e and to the eye alike.
 *
 * Framework-owned and mounted for every project, like {@see TableLagRuntime}, and written the
 * same way: by the daemon master of the node, which answers `test:table:refuse` itself. The row
 * is node-local and never travels the mesh. On production it stays empty for good - the command
 * socket refuses every `test:` command there, so nothing can write it.
 */
final class TableRefusalRuntime extends RtState
{
    /** Runtime item alias the framework mounts and the RT sync carries. */
    public const string RT_ITEM = 'hilosTableRefusalRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string tableKey = 'tableKey';

    /** Wire key of the table whose windows are refused; empty when no table is refused. */
    public string $tableKey = '';

    /**
     * Creates the singleton row with no table refused.
     *
     * @return static Table refusal runtime state refusing nothing
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
     * @throws InvalidFormatException When the row lost the table key or carries it as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->tableKey = self::requireString($row, self::tableKey);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The master writes the row and the workers build the windows, so without the diffs no worker
     * would ever refuse one.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries the table key as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->tableKey = self::patchString($diff, self::tableKey, $this->tableKey);
    }

    /**
     * @return string Runtime collection key for the table refusal singleton
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
            self::tableKey => $this->tableKey,
        ];
    }
}
