<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * AdminAudience - the answer to "who administers this installation" (HIL-279).
 *
 * Framework code sometimes has to reach the administrators with nobody at the screen: a
 * backup restore ends inside an agent, long after the connection that asked for it is
 * gone. {@see BrowserContext::isAdmin()} cannot answer there - it judges a user id the
 * caller already holds, and only in the browser layer - so the question is asked here
 * instead.
 *
 * The base answers from the framework's person table: every `hilos_user` row that says admin
 * and is not blocked, by the same flag the page-level gate reads, so a person who can open
 * the admin surface and a person who hears from it are the same person. A process with no
 * database layer has nobody to name and answers with nobody. A project points
 * {@see Hilos::ADMIN_AUDIENCE} at a subclass of its own only when it has something to add -
 * the chat demo leaves out merged accounts until the merge has a table of its own (HIL-1199).
 *
 * Reading the answer means reading storage, so it may well fail; that is why both methods
 * here declare it. Swallowing it into an empty list is refused on purpose - a caller
 * notifying about something that has already happened contains the failure itself, and one
 * that cannot must not be told the installation has no administrators when nobody actually
 * looked.
 */
class AdminAudience
{
    /**
     * The administrators of this installation: admin, and not blocked.
     *
     * A blocked administrator keeps a row that still says admin, and is no reader who can act
     * on what arrives.
     *
     * @return list<int> Durable user ids of the unblocked administrators, empty without a database layer
     * @throws DatabaseException When the users cannot be loaded
     * @throws LogicException When the user collection is not configured
     * @throws InvalidArgumentException When a loaded user object does not match the collection
     */
    protected static function userIds(): array
    {
        if (Hilos::$db === null) {
            return [];
        }

        $userIds = [];
        foreach (Hilos::$db->users->listAll() as $user) {
            if ($user->id !== null && $user->admin === true && $user->block !== true) {
                $userIds[] = $user->id;
            }
        }

        return $userIds;
    }

    /**
     * Deduplicates and reindexes what {@see userIds()} answered, so framework callers can take
     * the shape on faith: an override that collects ids while walking rows may repeat one and
     * may key by the id itself, and neither is worth making every call site handle.
     *
     * @return list<int> Durable admin user ids, each appearing once, in the order answered
     * @throws HilosException When the storage cannot answer who administers this installation
     */
    public static function all(): array
    {
        return array_values(array_unique(static::userIds()));
    }
}
