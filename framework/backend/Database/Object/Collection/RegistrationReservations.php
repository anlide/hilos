<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\RegistrationReservations as EntityRegistrationReservations;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * RegistrationReservations object collection.
 *
 * Persistence primitives of the reservation layer (HIL-415): hold a registration
 * for a TTL, read the hold, push it out, release it, and sweep the holds that ran
 * out. Every one of them is keyed by the SESSION since HIL-608 - a hold belongs to
 * the browser that started it - and the address is what several holds may share.
 * The orchestration (which method reserves, when a code is issued, what the
 * confirmed hold turns into) lives in {@see RegistrationReservationService}. The
 * hold carries no credential since HIL-825: the password is asked for after the
 * code, so nothing about it is minted or stored here.
 *
 * @extends Objects<ObjectRegistrationReservation>
 * @method ObjectRegistrationReservation|null current()
 * @method ObjectRegistrationReservation|null first()
 * @method ObjectRegistrationReservation|null last()
 * @method ObjectRegistrationReservation|null get(int|string $key)
 * @method ObjectRegistrationReservation|null offsetGet(mixed $offset)
 */
final class RegistrationReservations extends Objects
{
    public const string OBJECT_CLASS = ObjectRegistrationReservation::class;
    public const string ENTITY_COLLECTION_CLASS = EntityRegistrationReservations::class;
    public const string COLLECTION_KEY = HilosDbContext::registrationReservations;

    /**
     * Holds an identifier for one browser while the code that proves it travels.
     *
     * Mint write path of the reservation layer. The row carries the address, the
     * browser leading it and how long the hold lasts, and nothing else: since HIL-825
     * the password is asked for after the code, so there is no credential to store
     * and no follow-up write to store it with.
     *
     * The session's previous hold is released first, whatever address it named and
     * whether or not it had expired: a browser leads one registration at a time, so a
     * submit of another address ends the one before it rather than leaving two rows
     * the surface would have to choose between. That is also what the UNIQUE index
     * enforces, and losing a race against another socket of the SAME browser is the
     * only way to reach it ({@see DuplicateValueException}).
     *
     * Re-holding an address this session already holds starts the hold over, proof
     * and all: a fresh row is written and the old one goes with its
     * `code_accepted_at`. That is right rather than lossy - a new hold means a new
     * code is on its way, and a proof left standing beside it would let a person
     * walk past a letter they asked for.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $sessionToken Session cookie token of the browser leading this registration
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $ttlSeconds Seconds the registration stays held
     * @return ObjectRegistrationReservation The created reservation object
     * @throws EmptyValueException When the identifier or the session token is empty
     * @throws DuplicateValueException When another socket of this session inserted a hold meanwhile
     * @throws DatabaseException If the insert query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function createReservation(
        string $type,
        string $sessionToken,
        string $identifier,
        int $ttlSeconds,
    ): ObjectRegistrationReservation {
        if ($identifier === '') {
            throw new EmptyValueException('Reservation identifier is required');
        }
        if ($sessionToken === '') {
            throw new EmptyValueException('Reservation session token is required');
        }

        $standing = $this->findBySessionToken($sessionToken);
        if ($standing !== null) {
            $this->release($standing);
        }

        $reservation = ObjectRegistrationReservation::create();
        $reservation->type = $type;
        $reservation->identifier = $identifier;
        $reservation->sessionToken = $sessionToken;
        $reservation->expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        try {
            $reservation->sync();
        } catch (DuplicateEntryException) {
            throw new DuplicateValueException('session already holds a registration');
        }

        $id = $reservation->id;
        if ($id === null) {
            throw new DatabaseException('Reservation insert did not assign an id');
        }

        $this[$id] = $reservation;

        return $reservation;
    }

    /**
     * Finds the live registration one browser is leading.
     *
     * The UNIQUE index makes this at most one row, so "active" is the only question
     * left: an expired row still sits in the table until the sweeper (or this
     * session's next submit) removes it, and it holds nothing.
     *
     * @param string $sessionToken Session cookie token of the asking browser
     * @return ?ObjectRegistrationReservation Live reservation, or null when this browser holds none
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findActiveForSession(string $sessionToken): ?ObjectRegistrationReservation
    {
        $reservation = $this->findBySessionToken($sessionToken);
        if ($reservation === null || !$reservation->isActive(TimeHelper::getSqlDateTime())) {
            return null;
        }

        return $reservation;
    }

    /**
     * Pushes one browser's live hold out to a later moment.
     *
     * Resend path: the fresh code outlives the hold that carried the previous one, so
     * the hold follows the code. A session holding nothing is a no-op - there is
     * nothing to extend, and the caller answers the expired reservation on its own
     * branch.
     *
     * @param string $sessionToken Session cookie token of the browser that re-sent
     * @param string $expiresAtSql New expiry as an SQL datetime string
     * @throws DatabaseException If the lookup or expiry update query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function extendTo(string $sessionToken, string $expiresAtSql): void
    {
        $this->findActiveForSession($sessionToken)?->extendTo($expiresAtSql);
    }

    /**
     * Releases the reservation one browser holds, ending its registration.
     *
     * Called when the hold has served its purpose - the proof came back and the
     * account now exists - so the row would otherwise claim a registration that is
     * over. A reservation has no consumed state for the same reason a used ticket is
     * not kept: {@see findActiveForSession()} must answer "none" immediately after,
     * and the UNIQUE index leaves no room for a tombstone.
     *
     * @param string $sessionToken Session cookie token of the browser whose hold is over
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function consume(string $sessionToken): void
    {
        $reservation = $this->findBySessionToken($sessionToken);
        if ($reservation === null) {
            return;
        }

        $this->release($reservation);
    }

    /**
     * Drops every OTHER browser's hold on an identifier and names their sessions.
     *
     * What the browser that FINISHED a registration owes the ones that were racing it
     * (HIL-608, and since HIL-825 that is the browser which saved a password rather
     * than the one which proved the address first): the address has an account now, so
     * their registrations cannot finish and must not sit there refusing a second
     * attempt for the whole TTL. The losing session tokens are RETURNED rather than
     * merely counted - they are the list the "already taken" converge is built from,
     * and the only place that knows them is the moment their rows go.
     *
     * The winner is named by its session and skipped, not by its row: a browser that
     * proved an address it never reserved (a link answered on a fresh tab) holds
     * nothing here, and then every row on the address is a loser.
     *
     * @param string $identifier Normalized identifier that was just proven (lowercased email)
     * @param string $winnerSessionToken Session cookie token of the browser that proved it
     * @return list<string> Session tokens whose hold this call removed (empty when there were none)
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function releaseOthers(string $identifier, string $winnerSessionToken): array
    {
        $losers = [];
        foreach ($this->listByIdentifier($identifier) as $reservation) {
            $sessionToken = $reservation->sessionToken;
            if ($sessionToken === $winnerSessionToken) {
                continue;
            }
            $losers[] = $sessionToken;
            $this->release($reservation);
        }

        return $losers;
    }

    /**
     * Drops every reservation whose hold ran out and names the pairs it freed.
     *
     * The sweep primitive behind the cron rule: an abandoned registration must end,
     * and the sessions parked on its code step have to be told, which is why the
     * freed rows are RETURNED rather than merely counted - they are what the rollback
     * broadcast is built from ({@see RegistrationReservationSweeper}).
     *
     * PAIRS and not addresses (HIL-608): several browsers may be registering one
     * address, so an expired hold rolls back the session that owned it and leaves the
     * others on their code screens.
     *
     * @return list<array{sessionToken: string, identifier: string}> Session/identifier pairs this sweep freed
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function deleteExpired(): array
    {
        $now = TimeHelper::getSqlDateTime();

        $freed = [];
        foreach ($this->hydrateExpired($now) as $reservation) {
            $freed[] = [
                ObjectRegistrationReservation::sessionToken => $reservation->sessionToken,
                ObjectRegistrationReservation::identifier => $reservation->identifier,
            ];
            $this->release($reservation);
        }

        return $freed;
    }

    /**
     * Loads and caches the reservation row one session holds, live or expired.
     *
     * @param string $sessionToken Session cookie token of the asking browser
     * @return ?ObjectRegistrationReservation Reservation object, or null when this session holds none
     * @throws DatabaseException If the database query fails
     */
    private function findBySessionToken(string $sessionToken): ?ObjectRegistrationReservation
    {
        if ($sessionToken === '') {
            return null;
        }

        $entities = EntityRegistrationReservation::get([
            EntityRegistrationReservation::session_token => $sessionToken,
        ]);

        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }

            return $this->hydrateReservation($entity);
        }

        return null;
    }

    /**
     * Loads and caches every reservation standing on an identifier, live or expired.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<ObjectRegistrationReservation> Reservation objects (empty when the address is unheld)
     * @throws DatabaseException If the database query fails
     */
    private function listByIdentifier(string $identifier): array
    {
        if ($identifier === '') {
            return [];
        }

        $entities = EntityRegistrationReservation::get([
            EntityRegistrationReservation::identifier => $identifier,
        ]);

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            $result[] = $this->hydrateReservation($entity);
        }

        return $result;
    }

    /**
     * Loads and caches every reservation whose expiry has passed.
     *
     * @param string $nowSql Current time as an SQL datetime string
     * @return list<ObjectRegistrationReservation> Expired reservation objects (empty when none)
     * @throws DatabaseException If the database query fails
     */
    private function hydrateExpired(string $nowSql): array
    {
        $entities = EntityRegistrationReservation::get(
            '`' . EntityRegistrationReservation::expires_at . '` <= ?',
            [$nowSql],
        );

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            $result[] = $this->hydrateReservation($entity);
        }

        return $result;
    }

    /**
     * Wraps a freshly loaded row, REPLACING whatever this process had cached for it.
     *
     * Every lookup here queries the table (there is no other way in), so a cache that
     * outlived the query would buy nothing and cost freshness - which is exactly what it
     * cost (HIL-825). Two of this row's columns are written with a targeted UPDATE and
     * mirrored on the loaded entity ({@see ObjectRegistrationReservation::extendTo()},
     * {@see ObjectRegistrationReservation::markCodeAccepted()}), and that mirror reaches
     * only the process that wrote it: the write happens in the users library and the
     * session holder is another agent, so its copy of the row stayed as it was at first
     * sight, forever. A browser that had proved its address was handed the code step
     * again, and a countdown that had been extended still ran out at the old moment.
     *
     * Replacing rather than refreshing in place is safe because nothing holds a
     * reservation across calls: this collection is backend-only, never published, and
     * every caller uses the object it was just handed.
     *
     * @param EntityRegistrationReservation $entity Loaded row whose id is known to be set
     * @return ObjectRegistrationReservation Reservation object carrying the row as it is now
     */
    private function hydrateReservation(EntityRegistrationReservation $entity): ObjectRegistrationReservation
    {
        $id = (int)$entity->id;
        $this->hydrate($id, ObjectRegistrationReservation::fromEntity($entity));

        return $this->objects[$id];
    }

    /**
     * Deletes one reservation row and drops it from the in-memory index.
     *
     * @param ObjectRegistrationReservation $reservation Reservation to delete
     * @throws DatabaseException If the delete query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    private function release(ObjectRegistrationReservation $reservation): void
    {
        $id = $reservation->id;
        $reservation->delete();

        if ($id !== null) {
            unset($this[$id]);
        }
    }
}
