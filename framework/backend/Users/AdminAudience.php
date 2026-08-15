<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * AdminAudience - the project's answer to "who administers this installation" (HIL-279).
 *
 * Framework code sometimes has to reach the administrators with nobody at the screen: a
 * backup restore ends inside an agent, long after the connection that asked for it is
 * gone. {@see BrowserContext::isAdmin()} cannot answer there - it judges a user id the
 * caller already holds, and only in the browser layer - so the question is asked here
 * instead. A project points {@see Hilos::ADMIN_AUDIENCE} at its own subclass and answers
 * by overriding {@see userIds()}.
 *
 * The base answers with nobody. A project that never declared its administrators then
 * sends nothing, rather than mailing a list somebody guessed: a missing notification is
 * recoverable, a notification delivered to the wrong reader is not.
 *
 * Reading the answer usually means reading storage, so an implementation may well fail;
 * that is why both methods here declare it. Swallowing it into an empty list is refused
 * on purpose - a caller notifying about something that has already happened contains the
 * failure itself, and one that cannot must not be told the installation has no
 * administrators when nobody actually looked.
 */
abstract class AdminAudience
{
    /**
     * The administrators of this installation, as this project stores them.
     *
     * @return list<int> Durable user ids, empty when the project declares no administrators
     * @throws HilosException When the project's storage cannot answer who administers this installation
     */
    protected static function userIds(): array
    {
        return [];
    }

    /**
     * Deduplicates and reindexes what the project answered, so framework callers can take
     * the shape on faith: a project that collects ids while walking rows may repeat one and
     * may key by the id itself, and neither is worth making every call site handle.
     *
     * @return list<int> Durable admin user ids, each appearing once, in the project's order
     * @throws HilosException When the project's storage cannot answer who administers this installation
     */
    public static function all(): array
    {
        return array_values(array_unique(static::userIds()));
    }
}
