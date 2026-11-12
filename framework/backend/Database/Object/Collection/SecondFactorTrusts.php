<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Constants\TimeConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorTrusts as EntitySecondFactorTrusts;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\SecondFactorTrust as EntitySecondFactorTrust;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorTrust as ObjectSecondFactorTrust;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorTrusts object collection - the browsers a person asked not to be asked on again (HIL-494).
 *
 * One row per (session row, person). {@see trust()} writes or extends it and stamps that moment
 * as the trust's last grant, {@see isTrusted()} is the question the sign-in gate asks, and
 * {@see deleteForUser()} takes every trust of a person out when the second factor is switched
 * off or reset.
 *
 * A trust restriction ({@see SecondFactorTrustRestrictions}, HIL-1900) applies to every trust
 * last granted no later than the restriction was saved: such a trust lives no longer than the
 * restriction's limit, and a restriction of 0 days takes it out. {@see isTrusted()} counts the
 * restrictions before anybody applies them, and {@see applyRestrictions()} is the person's agent
 * writing them into the person's trusts. A grant after the save is outside the restriction, so
 * a fresh proof always outlives an older shortening.
 *
 * @extends Objects<ObjectSecondFactorTrust>
 * @method ObjectSecondFactorTrust|null current()
 * @method ObjectSecondFactorTrust|null first()
 * @method ObjectSecondFactorTrust|null last()
 * @method ObjectSecondFactorTrust|null get(int|string $key)
 * @method ObjectSecondFactorTrust|null offsetGet(mixed $offset)
 */
class SecondFactorTrusts extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorTrust::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorTrusts::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorTrusts;

    /**
     * Locks both account sets before merge reads them, without waiting for another writer.
     * The caller must hold a transaction; this read neither opens nor commits one.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        Database::sql(
            'SELECT `' . EntitySecondFactorTrust::user_id . '` FROM `' . EntitySecondFactorTrust::_table
                . '` WHERE `' . EntitySecondFactorTrust::user_id . '` IN (?, ?) ORDER BY `'
                . EntitySecondFactorTrust::user_id . '` FOR UPDATE NOWAIT',
            [$survivorId, $loserId],
        );
    }

    /**
     * Trusts a browser for a person until a moment, writing the pair or moving its end.
     * Either way the pair is granted now; the moment it was first written stays as it was.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, int $until): void
    {
        $now = TimeHelper::nowMs();
        $existing = $this->find($sessionId, $userId);
        if ($existing !== null) {
            $existing->trustedUntil = $until;
            $existing->grantedAt = $now;
            $existing->sync();

            return;
        }

        $trust = static::OBJECT_CLASS::create();
        $trust->sessionId = $sessionId;
        $trust->userId = $userId;
        $trust->trustedUntil = $until;
        $trust->createdAt = $now;
        $trust->grantedAt = $now;
        $trust->sync();

        if ($trust->id !== null) {
            $this[$trust->id] = $trust;
        }
    }

    /**
     * Whether a browser is trusted for a person right now.
     *
     * A restriction saved since the trust was last granted counts before the person's agent has
     * applied it: once its limit has passed - at once for one that takes the trust out - the pair
     * is not trusted, whatever its row still says. The restrictions are read from the table: the
     * settings library writes them in its own process.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person asking to be let in
     * @param int $allowedDays Current policy limit in days
     * @return bool True while a trust of the pair has not run out and no restriction saved since its grant has ended it
     * @throws DatabaseException When a lookup fails
     * @throws InvalidArgumentException When an entity query is given an invalid order direction
     * @throws HilosException When the restrictions cannot be read
     */
    public function isTrusted(int $sessionId, int $userId, int $allowedDays): bool
    {
        if ($allowedDays <= 0) {
            return false;
        }

        $trust = $this->find($sessionId, $userId);
        $now = TimeHelper::nowMs();
        $until = $now + $allowedDays * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        if ($trust === null || $trust->trustedUntil <= $now || $trust->trustedUntil > $until) {
            return false;
        }

        $limit = self::limitUnder(self::restrictionsSavedSince($trust->grantedAt), $trust->grantedAt);

        return $limit === null || $limit > $now;
    }

    /**
     * Limits trusts live at the save moment without lengthening any row.
     *
     * @param int $limitMs New latest expiry (epoch milliseconds)
     * @param int $savedAtMs Setting save moment (epoch milliseconds)
     * @throws DatabaseException When a lookup or update fails
     * @throws InvalidArgumentException When a query or sync announcement is invalid
     * @throws WriteNotAllowedException When this process cannot write a row
     * @throws SourceChangeSubscriberException Whatever a store subscriber raises
     * @throws ObjectGetIdStringNotImplementedException When a row id cannot be named
     */
    public function capLiveUntil(int $limitMs, int $savedAtMs): void
    {
        $where = '`' . EntitySecondFactorTrust::trusted_until . '` > ? AND `'
            . EntitySecondFactorTrust::trusted_until . '` > ? AND `'
            . EntitySecondFactorTrust::created_at . '` <= ?';
        $liveTrusts = static::entityClass()::get( // fresh-sql: the person's agent caps and removes trusts in its own process.
            $where,
            [$limitMs, $savedAtMs, $savedAtMs],
        );
        foreach ($this->hydrateAll($liveTrusts) as $trust) {
            $trust->trustedUntil = $limitMs;
            $trust->sync();
        }
    }

    /**
     * @param int $userId Person whose other browsers lose trust
     * @param int $keepSessionId Current browser's session row
     * @throws DatabaseException When a lookup or delete fails
     * @throws InvalidArgumentException When a query or sync announcement is invalid
     * @throws WriteNotAllowedException When this process cannot remove a row
     * @throws SourceChangeSubscriberException Whatever a store subscriber raises
     */
    public function deleteForUserExceptSession(int $userId, int $keepSessionId): void
    {
        $where = '`' . EntitySecondFactorTrust::user_id . '` = ? AND `'
            . EntitySecondFactorTrust::session_id . '` <> ?';
        $otherTrusts = static::entityClass()::get( // fresh-sql: the session holder grants trusts in its own process.
            $where,
            [$userId, $keepSessionId],
        );
        foreach ($this->hydrateAll($otherTrusts) as $trust) {
            $this->remove($trust);
        }
    }

    /**
     * @param int $sessionId Browser's session row
     * @param int $userId Person whose trust of that browser ends
     * @throws DatabaseException When a lookup or delete fails
     * @throws InvalidArgumentException When a query or sync announcement is invalid
     * @throws WriteNotAllowedException When this process cannot remove a row
     * @throws SourceChangeSubscriberException Whatever a store subscriber raises
     */
    public function deleteForPair(int $sessionId, int $userId): void
    {
        $trust = $this->find($sessionId, $userId);
        if ($trust !== null) {
            $this->remove($trust);
        }
    }

    /**
     * Deletes every trust of a person.
     *
     * @param int $userId Person whose trusts to delete
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $personTrusts = static::entityClass()::get( // fresh-sql: the session holder grants trusts in its own process.
            [EntitySecondFactorTrust::user_id => $userId],
        );
        foreach ($this->hydrateAll($personTrusts) as $trust) {
            $this->remove($trust);
        }
    }

    /**
     * Writes the stored trust restrictions into a person's trusts (HIL-1900).
     *
     * A restriction applies to a trust last granted no later than it was saved. A trust under a
     * restriction of 0 days is removed; any other trust under restrictions is cut to the earliest
     * of their limits when it runs longer. A trust under none is left as it is, and so are its grant
     * and its first writing. Applying again, or in another order, changes nothing more: the cut is a
     * minimum and the removal is final.
     *
     * The trusts and the restrictions are read from the table: the session holder grants and extends
     * trusts in its own process and the settings library saves restrictions in its own, and a trust
     * granted again a moment ago must be seen as granted after the restriction. The caller holds the
     * transaction the writes stand or fall in, and the rows are written in id order.
     *
     * @param int $userId Person whose trusts the restrictions are applied to
     * @throws DatabaseException When a lookup or a write fails
     * @throws InvalidArgumentException When an entity query or a queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write a row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException When a row id cannot be named
     * @throws HilosException When the restrictions cannot be read
     */
    public function applyRestrictions(int $userId): void
    {
        $personTrusts = static::entityClass()::get( // fresh-sql: the session holder grants and extends trusts in its own process.
            [EntitySecondFactorTrust::user_id => $userId],
            orderBy: [EntitySecondFactorTrust::id => SqlSortDirection::ASC],
        );
        $earliestGrant = null;
        foreach ($personTrusts as $entity) {
            $earliestGrant = min($earliestGrant ?? $entity->granted_at, $entity->granted_at);
        }
        if ($earliestGrant === null) {
            return;
        }

        $restrictions = self::restrictionsSavedSince($earliestGrant);
        foreach ($personTrusts as $entity) {
            $id = $entity->id;
            $limit = self::limitUnder($restrictions, $entity->granted_at);
            if ($id === null || $limit === null) {
                continue;
            }
            $revoked = self::revokedUnder($restrictions, $entity->granted_at);
            if (!$revoked && $entity->trusted_until <= $limit) {
                continue;
            }

            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            if ($revoked) {
                $this->remove($this->objects[$id]);
                continue;
            }
            $this->objects[$id]->trustedUntil = $limit;
            $this->objects[$id]->sync();
        }
    }

    /**
     * @param EntityCollection<EntitySecondFactorTrust> $entities Matching rows
     * @return list<ObjectSecondFactorTrust> Hydrated row objects
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $result[] = $this->objects[$id];
        }

        return $result;
    }

    /**
     * @param ObjectSecondFactorTrust $trust Row to remove
     * @throws DatabaseException When the delete fails
     * @throws InvalidArgumentException When a DB-sync announcement is invalid
     * @throws WriteNotAllowedException When this process cannot write the row
     * @throws SourceChangeSubscriberException Whatever a store subscriber raises
     */
    private function remove(ObjectSecondFactorTrust $trust): void
    {
        $id = $trust->id;
        $trust->delete();
        unset($this[$id]);
    }

    /**
     * Loads the trust of one (browser, person) pair, if there is one.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person
     * @return ?ObjectSecondFactorTrust The trust row, or null when the pair has none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    private function find(int $sessionId, int $userId): ?ObjectSecondFactorTrust
    {
        $entity = static::entityClass()::get([ // fresh-sql: the holder and the person's agent write the pair in separate processes.
            EntitySecondFactorTrust::session_id => $sessionId,
            EntitySecondFactorTrust::user_id => $userId,
        ])->first();
        if ($entity === null || $entity->id === null) {
            return null;
        }
        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }

    /**
     * @param int $moment Earliest grant the restrictions are read for (epoch milliseconds)
     * @return SecondFactorTrustRestrictions Restrictions saved at or after the moment, read from the table
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException When the restrictions cannot be read
     */
    private static function restrictionsSavedSince(int $moment): SecondFactorTrustRestrictions
    {
        return Hilos::$db->secondFactorTrustRestrictions->getObjectCollection()->savedSince($moment);
    }

    /**
     * Latest moment a trust may be trusted until under the restrictions that apply to it.
     *
     * @param SecondFactorTrustRestrictions $restrictions Restrictions to weigh
     * @param int $grantedAt Moment the trust was last granted (epoch milliseconds)
     * @return ?int Earliest limit among the restrictions saved no earlier than the grant, or null when none is
     */
    private static function limitUnder(SecondFactorTrustRestrictions $restrictions, int $grantedAt): ?int
    {
        $limit = null;
        foreach ($restrictions as $restriction) {
            if ($restriction->appliesTo($grantedAt)) {
                $limit = min($limit ?? $restriction->limitAt(), $restriction->limitAt());
            }
        }

        return $limit;
    }

    /**
     * @param SecondFactorTrustRestrictions $restrictions Restrictions to weigh
     * @param int $grantedAt Moment the trust was last granted (epoch milliseconds)
     * @return bool True when a restriction of 0 days was saved no earlier than the grant
     */
    private static function revokedUnder(SecondFactorTrustRestrictions $restrictions, int $grantedAt): bool
    {
        foreach ($restrictions as $restriction) {
            if ($restriction->revokes() && $restriction->appliesTo($grantedAt)) {
                return true;
            }
        }

        return false;
    }
}
