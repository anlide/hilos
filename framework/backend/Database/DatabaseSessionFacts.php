<?php

declare(strict_types=1);

namespace Hilos\Database;

/** Snapshot of the server member and session answering one connection index. */
final readonly class DatabaseSessionFacts
{
    /**
     * @param string $member Server hostname
     * @param int $connectionId Server connection id
     * @param int $wsrepSyncWait Session causal-read bitmask
     * @param bool $wsrepDirtyReads Whether the session allows dirty reads
     */
    public function __construct(
        public string $member,
        public int $connectionId,
        public int $wsrepSyncWait,
        public bool $wsrepDirtyReads,
    ) {
    }
}
