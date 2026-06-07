<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Mutable in-memory snapshot of a browser session row.
 *
 * Caches the persisted id together with the currently known user-agent and
 * accept-language dictionary ids so the collector can detect changes without
 * re-reading the row. The current* fields are updated in place as new values
 * arrive during the session lifetime.
 */
final class BrowserSessionState
{
    /**
     * @param int $id Persisted browser session id
     * @param ?int $currentUserAgentId Current user-agent dictionary id, or null when unknown
     * @param ?int $currentAcceptLanguageId Current accept-language dictionary id, or null when unknown
     */
    public function __construct(
        public readonly int $id,
        public ?int $currentUserAgentId,
        public ?int $currentAcceptLanguageId,
    ) {
    }
}
