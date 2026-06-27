<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * The presence source the Hilos users table merges over its DB users.
 *
 * A project binds its own runtime connection collection as the presence source
 * by implementing this interface; the framework users table reads presence
 * through it and never imports the project's runtime collection type. This is
 * the backend twin of the frontend `connections` slot.
 */
interface HilosPresenceSource
{
    /**
     * Summarizes a user's active runtime connections.
     *
     * @param ?int $userId User id to summarize, or null for an empty summary
     * @return HilosUserPresenceSummary Presence and active session count for the user
     */
    public function summaryForUser(?int $userId): HilosUserPresenceSummary;
}
