<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SelfBroadcastRegistry - Remembers runtime sync ids this worker broadcast so it can
 * skip re-applying its own RT changes when the broadcast loops back to it.
 *
 * DB frames are not registered here: a DB echo is recognized by the emitter stamp
 * the frame carries ({@see SignalRouter::shouldSkipDbSyncApply()}).
 *
 * Registrations are bounded so a broadcast that is never applied back cannot leak
 * memory in a long-running daemon. When the cap is exceeded the oldest registration
 * is evicted. An evicted or already consumed registration lets this worker's own
 * stale echo apply over a newer write of the same state; HIL-2104 fixes that for RT.
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
     * {@see SignalRouter::queueRtSyncSignal()} calls this only when the payload names
     * both parts, so this one holds no guard of its own. Re-registering refreshes
     * recency, and the oldest registration is evicted once the cap is exceeded.
     *
     * @param string $collectionKey Collection key for sync
     * @param string $id Runtime state id
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
     * @param string $id Runtime state id
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
