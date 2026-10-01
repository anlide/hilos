<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * In-memory snapshot of a browser session row.
 *
 * Caches the persisted id together with the currently known user-agent and
 * accept-language dictionary ids so the store can detect changes without
 * re-reading the row. A change replaces the snapshot instead of updating it in
 * place: the store sets its cache aside while a transaction runs, and a snapshot
 * changed in place would carry a rolled-back value past the rollback
 * ({@see AnalyticsIdCache}).
 */
final readonly class BrowserSessionState
{
    /**
     * @param int $id Persisted browser session id
     * @param ?int $currentUserAgentId Current user-agent dictionary id, or null when unknown
     * @param ?int $currentAcceptLanguageId Current accept-language dictionary id, or null when unknown
     */
    public function __construct(
        public int $id,
        public ?int $currentUserAgentId,
        public ?int $currentAcceptLanguageId,
    ) {
    }
}
