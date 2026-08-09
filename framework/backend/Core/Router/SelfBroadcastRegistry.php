<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SelfBroadcastRegistry - Remembers sync ids this worker broadcast so it can skip
 * re-applying its own DB/RT changes when the broadcast loops back to it.
 *
 * Best-effort dedup, not a correctness store: registrations are bounded so a
 * broadcast that is never applied back cannot leak memory in a long-running
 * daemon. When the cap is exceeded the oldest registration is evicted; the worst
 * case is one redundant self-apply, never a wrong result.
 */
final class SelfBroadcastRegistry
{
    /** @var int Default number of pending registrations kept before FIFO eviction */
    public const int DEFAULT_MAX_ENTRIES = 10000;

    /** @var array<string, true> Registered "collectionKey:id" keys awaiting a self-apply skip */
    private array $ids = [];

    /**
     * @param int $maxEntries Maximum registrations kept before evicting the oldest
     */
    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
    }

    /**
     * Records a broadcast id so the originating worker can skip re-applying it.
     *
     * The queue methods of {@see SignalRouter} call this only when the payload names
     * both parts, so this one holds no guard of its own. Re-registering refreshes
     * recency, and the oldest registration is evicted once the cap is exceeded.
     *
     * @param string $collectionKey Collection key for sync
     * @param string $id Entity id (idString for DB, stateId for RT)
     */
    public function register(string $collectionKey, string $id): void
    {
        $key = $collectionKey . ':' . $id;
        unset($this->ids[$key]);
        $this->ids[$key] = true;

        if (count($this->ids) > $this->maxEntries) {
            array_shift($this->ids);
        }
    }

    /**
     * Consumes a registration; true when this id was our own broadcast.
     *
     * @param string $collectionKey Collection key for sync
     * @param string $id Entity id (idString for DB, stateId for RT)
     * @return bool True if registered (now removed), so the apply should be skipped
     */
    public function consume(string $collectionKey, string $id): bool
    {
        $key = $collectionKey . ':' . $id;
        if (isset($this->ids[$key])) {
            unset($this->ids[$key]);
            return true;
        }

        return false;
    }
}
