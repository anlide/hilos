<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\RegistrationReservations as EntityRegistrationReservations;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * RegistrationReservations object collection.
 *
 * Persistence primitives of the reservation layer (HIL-415): hold a registration
 * for a TTL, read the hold, push it out, release it, and sweep the holds that ran
 * out. Every one of them is keyed by the SESSION since HIL-608 - a hold belongs to
 * the browser that started it - and the address is what several holds may share.
 * The orchestration (which method reserves, when a code is issued, what the
 * confirmed hold turns into) lives in {@see RegistrationReservationService}; the
 * credential hash is minted here with a targeted query so it never reaches the ORM
 * columns.
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
     * Holds an identifier for one browser, storing the credential the account will get.
     *
     * Mint write path of the reservation layer, symmetric with the identity layer's
     * {@see Identities::createPasswordIdentity()}: the plaintext secret is hashed here
     * and written with a targeted UPDATE, so it is minted and stored entirely inside
     * the layer and never reaches the ORM columns, the object/view surface, or the
     * cross-worker sync bus. The row is first inserted through the ORM (which carries
     * the non-secret columns and assigns the id) and the hash is then set with a
     * follow-up UPDATE.
     *
     * The session's previous hold is released first, whatever address it named and
     * whether or not it had expired: a browser leads one registration at a time, so a
     * submit of another address ends the one before it rather than leaving two rows
     * the surface would have to choose between. That is also what the UNIQUE index
     * enforces, and losing a race against another socket of the SAME browser is the
     * only way to reach it ({@see DuplicateValueException}).
     *
     * The credential FOLLOWS THE ADDRESS inside one browser: re-holding an address
     * this session already holds live, with no new secret, carries the stored hash
     * onto the new row. That is what makes "submitted a password, then asked for a
     * link" end in an account with both ways in (HIL-608) instead of silently losing
     * the password. A hold that has already expired carries nothing - that attempt is
     * over - and neither does a hold on a different address, whose credential was
     * chosen for an inbox this one has not proven.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $sessionToken Session cookie token of the browser leading this registration
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?string $plainSecret Plaintext credential to hash and store, or null for a method that carries none
     * @param int $ttlSeconds Seconds the registration stays held
     * @return ObjectRegistrationReservation The created reservation object
     * @throws EmptyValueException When the identifier or the session token is empty
     * @throws DuplicateValueException When another socket of this session inserted a hold meanwhile
     * @throws DatabaseException If the insert or secret write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    public function createReservation(
        string $type,
        string $sessionToken,
        string $identifier,
        ?string $plainSecret,
        int $ttlSeconds,
    ): ObjectRegistrationReservation {
        if ($identifier === '') {
            throw new EmptyValueException('Reservation identifier is required');
        }
        if ($sessionToken === '') {
            throw new EmptyValueException('Reservation session token is required');
        }

        $carriedHash = null;
        $standing = $this->findBySessionToken($sessionToken);
        if ($standing !== null) {
            if ($standing->identifier === $identifier && $standing->isActive(TimeHelper::getSqlDateTime())) {
                $carriedHash = $standing->readSecretHash();
            }
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

        $secretHash = $plainSecret !== null && $plainSecret !== ''
            ? password_hash($plainSecret, PASSWORD_DEFAULT)
            : $carriedHash;
        if ($secretHash !== null) {
            $this->writeSecretHash($id, $secretHash);
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
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
     * What the first proof of an address owes the browsers that were racing it
     * (HIL-608): the address has an account now, so their registrations cannot finish
     * and must not sit there refusing a second attempt for the whole TTL. The losing
     * session tokens are RETURNED rather than merely counted - they are the list the
     * "already taken" converge is built from, and the only place that knows them is
     * the moment their rows go.
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
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
     * Returns the cached object for a loaded row, wrapping it on first sight.
     *
     * @param EntityRegistrationReservation $entity Loaded row whose id is known to be set
     * @return ObjectRegistrationReservation Cached reservation object for the row
     */
    private function hydrateReservation(EntityRegistrationReservation $entity): ObjectRegistrationReservation
    {
        $id = (int)$entity->id;
        if (!isset($this->objects[$id])) {
            $this->hydrate($id, ObjectRegistrationReservation::fromEntity($entity));
        }

        return $this->objects[$id];
    }

    /**
     * Stores a credential hash on a reservation row without mapping it.
     *
     * @param int $id Reservation row id
     * @param string $secretHash Bcrypt hash to store
     * @throws DatabaseException If the secret write query fails
     */
    private function writeSecretHash(int $id, string $secretHash): void
    {
        DbWriteGuard::guardItemWrite(static::COLLECTION_KEY, (string)$id, TruthSourceOperation::Update);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($secretHash));
        $params->add(SqlParam::int($id));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `' . EntityRegistrationReservation::secret
                . '` = ? WHERE `' . EntityRegistrationReservation::id . '` = ?',
            $params,
        );
    }

    /**
     * Deletes one reservation row and drops it from the in-memory index.
     *
     * @param ObjectRegistrationReservation $reservation Reservation to delete
     * @throws DatabaseException If the delete query fails
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
