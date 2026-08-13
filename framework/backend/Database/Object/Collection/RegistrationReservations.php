<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
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
use Hilos\Utils\Helpers\TimeHelper;

/**
 * RegistrationReservations object collection.
 *
 * Persistence primitives of the reservation layer (HIL-415): hold an identifier
 * for a TTL, read the hold, push it out, release it, and sweep the holds that
 * ran out. The orchestration (which method reserves, when a code is issued, what
 * the confirmed hold turns into) lives in
 * {@see RegistrationReservationService}; the credential hash is minted here with
 * a targeted query so it never reaches the ORM columns.
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
     * Holds an identifier for a TTL, storing the credential the account will get.
     *
     * Mint write path of the reservation layer, symmetric with the identity
     * layer's {@see Identities::createPasswordIdentity()}: the plaintext secret is
     * hashed here and written with a targeted UPDATE, so it is minted and stored
     * entirely inside the layer and never reaches the ORM columns, the object/view
     * surface, or the cross-worker sync bus. The row is first inserted through the
     * ORM (which carries the non-secret columns and assigns the id) and the hash is
     * then set with a follow-up UPDATE.
     *
     * An expired row for the same identifier is released first: the hold it names
     * is over, the sweeper simply has not come round yet, and the UNIQUE index does
     * not distinguish the two. A LIVE row is what the unique index is for - two
     * submits racing on a free address both reach the insert, and the loser is told
     * so here ({@see DuplicateValueException}) rather than overwriting a hold that
     * already mailed its code.
     *
     * @param string $type Reserving method (see IdentityType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?string $plainSecret Plaintext credential to hash and store, or null for a method that carries none
     * @param int $ttlSeconds Seconds the identifier stays held
     * @return ObjectRegistrationReservation The created reservation object
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When another live reservation already holds the identifier
     * @throws DatabaseException If the insert or secret write query fails
     */
    public function createReservation(
        string $type,
        string $identifier,
        ?string $plainSecret,
        int $ttlSeconds,
    ): ObjectRegistrationReservation {
        if ($identifier === '') {
            throw new EmptyValueException('Reservation identifier is required');
        }

        $stale = $this->findByIdentifier($identifier);
        if ($stale !== null && !$stale->isActive(TimeHelper::getSqlDateTime())) {
            $this->release($stale);
        }

        $reservation = ObjectRegistrationReservation::create();
        $reservation->type = $type;
        $reservation->identifier = $identifier;
        $reservation->expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        try {
            $reservation->sync();
        } catch (DuplicateEntryException) {
            throw new DuplicateValueException('identifier already reserved');
        }

        $id = $reservation->id;
        if ($id === null) {
            throw new DatabaseException('Reservation insert did not assign an id');
        }

        if ($plainSecret !== null && $plainSecret !== '') {
            $params = SqlParamCollection::empty();
            $params->add(SqlParam::string(password_hash($plainSecret, PASSWORD_DEFAULT)));
            $params->add(SqlParam::int($id));
            Database::sql(
                'UPDATE `' . EntityRegistrationReservation::_table . '` SET `' . EntityRegistrationReservation::secret
                    . '` = ? WHERE `' . EntityRegistrationReservation::id . '` = ?',
                $params,
            );
        }

        $this->objects[$id] = $reservation;

        return $reservation;
    }

    /**
     * Finds the live reservation holding an identifier.
     *
     * The UNIQUE index makes this at most one row, so "active" is the only
     * question left: an expired row still sits in the table until the sweeper
     * (or the next reservation of the same address) removes it, and it holds
     * nothing.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return ?ObjectRegistrationReservation Live reservation, or null when the identifier is free
     * @throws DatabaseException If the database query fails
     */
    public function findActive(string $identifier): ?ObjectRegistrationReservation
    {
        $reservation = $this->findByIdentifier($identifier);
        if ($reservation === null || !$reservation->isActive(TimeHelper::getSqlDateTime())) {
            return null;
        }

        return $reservation;
    }

    /**
     * Pushes the live hold on an identifier out to a later moment.
     *
     * Resend path: the fresh code outlives the hold that carried the previous one,
     * so the hold follows the code. A free identifier is a no-op - there is
     * nothing to extend, and the caller answers the expired reservation on its own
     * branch.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $expiresAtSql New expiry as an SQL datetime string
     * @throws DatabaseException If the lookup or expiry update query fails
     */
    public function extendTo(string $identifier, string $expiresAtSql): void
    {
        $this->findActive($identifier)?->extendTo($expiresAtSql);
    }

    /**
     * Releases the reservation holding an identifier, freeing the address.
     *
     * Called when the hold has served its purpose - the code came back and the
     * account now exists, so the identity carries the address and a second row
     * claiming to hold it would block the person who just registered from ever
     * registering again. A reservation has no consumed state for the same reason a
     * used ticket is not kept: {@see findActive()} must answer "free" immediately
     * after, and the UNIQUE index leaves no room for a tombstone.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function consume(string $identifier): void
    {
        $reservation = $this->findByIdentifier($identifier);
        if ($reservation === null) {
            return;
        }

        $this->release($reservation);
    }

    /**
     * Drops every reservation whose hold ran out and names the freed identifiers.
     *
     * The sweep primitive behind the cron rule: an abandoned registration must
     * free its address, and the sessions parked on its code step must be told,
     * which is why the freed identifiers are RETURNED rather than merely counted -
     * they are the address list the converge broadcast is built from
     * ({@see RegistrationReservationSweeper}).
     *
     * @return list<string> Identifiers freed by this sweep (empty when none expired)
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function deleteExpired(): array
    {
        $now = TimeHelper::getSqlDateTime();

        $freed = [];
        foreach ($this->hydrateExpired($now) as $reservation) {
            $freed[] = $reservation->identifier;
            $this->release($reservation);
        }

        return $freed;
    }

    /**
     * Loads and caches the reservation row for an identifier, live or expired.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return ?ObjectRegistrationReservation Reservation object, or null when no row holds the identifier
     * @throws DatabaseException If the database query fails
     */
    private function findByIdentifier(string $identifier): ?ObjectRegistrationReservation
    {
        if ($identifier === '') {
            return null;
        }

        $entities = EntityRegistrationReservation::get([
            EntityRegistrationReservation::identifier => $identifier,
        ]);

        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->objects[$entity->id] = ObjectRegistrationReservation::fromEntity($entity);
            }

            return $this->objects[$entity->id];
        }

        return null;
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
            if (!isset($this->objects[$entity->id])) {
                $this->objects[$entity->id] = ObjectRegistrationReservation::fromEntity($entity);
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }

    /**
     * Deletes one reservation row and drops it from the in-memory index.
     *
     * @param ObjectRegistrationReservation $reservation Reservation to delete
     * @throws DatabaseException If the delete query fails
     */
    private function release(ObjectRegistrationReservation $reservation): void
    {
        $id = $reservation->id;
        $reservation->delete();

        if ($id !== null) {
            unset($this->objects[$id]);
        }
    }
}
