<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorTrusts as EntitySecondFactorTrusts;
use Hilos\Database\Entity\Item\SecondFactorTrust as EntitySecondFactorTrust;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorTrust as ObjectSecondFactorTrust;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorTrusts object collection - the browsers a person asked not to be asked on again (HIL-494).
 *
 * One row per (session row, person). {@see trust()} writes or extends it,
 * {@see isTrusted()} is the question the sign-in gate asks, and {@see deleteForUser()}
 * takes every trust of a person out when the second factor is switched off or reset.
 *
 * @extends Objects<ObjectSecondFactorTrust>
 * @method ObjectSecondFactorTrust|null current()
 * @method ObjectSecondFactorTrust|null first()
 * @method ObjectSecondFactorTrust|null last()
 * @method ObjectSecondFactorTrust|null get(int|string $key)
 * @method ObjectSecondFactorTrust|null offsetGet(mixed $offset)
 */
final class SecondFactorTrusts extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorTrust::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorTrusts::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorTrusts;

    /**
     * Trusts a browser for a person until a moment, writing the pair or moving its end.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param string $until Moment the trust runs out (SQL datetime)
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, string $until): void
    {
        $existing = $this->find($sessionId, $userId);
        if ($existing !== null) {
            $existing->trustedUntil = $until;
            $existing->sync();

            return;
        }

        $trust = ObjectSecondFactorTrust::create();
        $trust->sessionId = $sessionId;
        $trust->userId = $userId;
        $trust->trustedUntil = $until;
        $trust->createdAt = TimeHelper::getSqlDateTime();
        $trust->sync();

        if ($trust->id !== null) {
            $this[$trust->id] = $trust;
        }
    }

    /**
     * Whether a browser is trusted for a person right now.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person asking to be let in
     * @return bool True while a trust of the pair has not run out
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function isTrusted(int $sessionId, int $userId): bool
    {
        $trust = $this->find($sessionId, $userId);

        return $trust !== null && $trust->trustedUntil > TimeHelper::getSqlDateTime();
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
        foreach (EntitySecondFactorTrust::get([EntitySecondFactorTrust::user_id => $userId]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, ObjectSecondFactorTrust::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
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
        $entity = EntitySecondFactorTrust::get([
            EntitySecondFactorTrust::session_id => $sessionId,
            EntitySecondFactorTrust::user_id => $userId,
        ])->first();
        if ($entity === null || $entity->id === null) {
            return null;
        }
        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, ObjectSecondFactorTrust::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
