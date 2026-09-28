<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\AdminViewMode\AdminViewModeStartup;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * AdminViewModeRuntime - the singleton runtime state of the admin view mode of a node (HIL-1249).
 *
 * Whether a non-admin may open the admin section on this node to look and change nothing. The
 * variable decides it only at the start - the environment of a living process cannot change - so
 * the answer lives here, where a lever can change it too.
 *
 * Framework-owned and mounted for every project, like {@see TableRefusalRuntime}, and written the
 * same way: only by the daemon master of the node - once at its start ({@see AdminViewModeStartup})
 * and in answer to `test:admin-view-mode` on a stand. The workers read it: a worker is a process of
 * its own, handed the row by the master when it comes up and kept current through the RT sync.
 * The row is node-local and never travels the mesh. It stays inert (false) while the mode is off.
 */
final class AdminViewModeRuntime extends RtState
{
    /** Runtime item alias the framework mounts and the RT sync carries. */
    public const string RT_ITEM = 'hilosAdminViewModeRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string enabled = 'enabled';

    /** Whether a non-admin may open the admin section on this node to look. */
    public bool $enabled = false;

    /**
     * Creates the singleton row with the mode off.
     *
     * @return static Admin view mode runtime state with the mode off
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
     * @throws InvalidFormatException When the row lost the mode or carries it as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->enabled = self::requireBool($row, self::enabled);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The master writes the row and the workers answer the viewers, so without the diffs a lever
     * pulled on a living node would reach no worker.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries the mode as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->enabled = self::patchBool($diff, self::enabled, $this->enabled);
    }

    /**
     * @return string Runtime collection key for the admin view mode singleton
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
            self::enabled => $this->enabled,
        ];
    }
}
