<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\Exception\State\RtStateReadOnlyException;
use Hilos\Runtime\Exception\State\RtStateUnserializeException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Base class for runtime state objects.
 *
 * RtState is the single source of truth for runtime data (analogous to Object_).
 * Child classes should expose row fields as real typed properties; use PHP 8.4
 * asymmetric visibility (`public private(set)`) for immutable ids.
 *
 * {@see sync()} broadcasts RT_SYNC_UPDATED for fields changed since {@see markRtSyncBaseline()} (worker sync only).
 * After inbound RT applyDiff, callers must call {@see markRtSyncBaseline()} so local sync() does not re-send stale diffs.
 */
abstract class RtState
{
    /**
     * Copy of the row as of the last "synced" moment: {@see sync()} diffs {@see toArray()} against this
     * to know which fields to put in RT_SYNC_UPDATED (same idea as Object_ entity vs entitySync, without DB).
     * Written only by {@see markRtSyncBaseline()} and at the end of {@see sync()}.
     *
     * @var array<string, mixed>
     */
    private array $rtSyncBaseline = [];

    /**
     * Protected constructor. Child classes must use static factory methods (e.g. fromRow).
     */
    protected function __construct()
    {
    }

    /**
     * Protected clone. Prevents cloning of RtState instances.
     */
    protected function __clone()
    {
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * RtState instances cannot be safely unserialized as they represent
     * transient runtime state that should not persist across processes.
     *
     * @throws RtStateUnserializeException Always, unserialization not allowed
     */
    public function __wakeup(): void
    {
        throw new RtStateUnserializeException('RtState cannot be unserialized');
    }

    /**
     * Returns state as array for var_dump/print_r debug output.
     *
     * @return array<string, mixed> State fields as associative array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Legacy fallback for dynamic property reads. Concrete state fields should be real typed properties.
     *
     * @param string $name Property name
     * @return mixed Property value (child classes must override; base always throws)
     * @throws RtStatePropertyNotFoundException When property does not exist (base implementation)
     */
    public function __get(string $name): mixed
    {
        $className = static::class;
        throw new RtStatePropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Legacy fallback for dynamic property writes. Concrete writable state fields should be real typed properties.
     *
     * @param string $name Property name
     * @param mixed $value New value
     *
     * @throws RtStateReadOnlyException When the property is not writable on this state class
     */
    public function __set(string $name, mixed $value): void
    {
        $className = static::class;
        throw new RtStateReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtState is read-only from outside."
        );
    }

    /**
     * RT collection key for sync. Empty string skips queue while still advancing the baseline.
     *
     * @return string Runtime collection key, or an empty string to skip sync enqueue
     */
    public static function getRtCollectionKey(): string
    {
        return '';
    }

    /**
     * Resets diff baseline to the current row (call after create/fromRow and after inbound RT applyDiff).
     */
    public function markRtSyncBaseline(): void
    {
        $this->rtSyncBaseline = $this->toArray();
    }

    /**
     * Queue RT_SYNC_UPDATED for all fields that differ from the last baseline, then advance the baseline.
     * Does not persist to DB; cross-worker runtime sync only.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function sync(): void
    {
        $current = $this->toArray();
        $diff = [];
        foreach ($current as $k => $v) {
            if (($this->rtSyncBaseline[$k] ?? null) !== $v) {
                $diff[$k] = $v;
            }
        }
        if ($diff === []) {
            return;
        }

        $collectionKey = static::getRtCollectionKey();
        if ($collectionKey !== '' && Hilos::$sr !== null) {
            RtTruthSourceRegistry::checkCanWriteState($collectionKey, $this->getId());
            Hilos::$sr->queueRtSyncSignal(
                SignalConstants::RT_SYNC_UPDATED,
                new RtSyncUpdatedSignalData($collectionKey, $this->getId(), $diff),
            );
        }

        $this->rtSyncBaseline = $current;
    }

    /**
     * Get unique identifier for this state
     *
     * Must be implemented by child classes to provide a unique key
     * for storing this state in the collection.
     *
     * @return string Unique identifier
     */
    abstract public function getId(): string;

    /**
     * Create state instance from row (array) for RT sync deserialization.
     *
     * @param array<string, mixed> $row Full state data (keys match toArray() output)
     * @return static State instance
     */
    abstract public static function fromRow(array $row): static;

    /**
     * Apply diff to state fields for RT sync update.
     *
     * Override in child classes to support updates. Default no-op.
     *
     * @param array<string, mixed> $diff Changed fields => values
     */
    public function applyDiff(array $diff): void
    {
    }

    /**
     * Convert state to array representation.
     *
     * @return array<string, mixed> State fields as associative array
     */
    abstract public function toArray(): array;
}
