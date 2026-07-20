<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Identities as EntityIdentities;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * Identities object collection.
 *
 * @extends Objects<ObjectIdentity>
 * @method ObjectIdentity|null current()
 * @method ObjectIdentity|null first()
 * @method ObjectIdentity|null last()
 * @method ObjectIdentity|null get(int|string $key)
 * @method ObjectIdentity|null offsetGet(mixed $offset)
 */
final class Identities extends Objects
{
    public const string OBJECT_CLASS = ObjectIdentity::class;
    public const string ENTITY_COLLECTION_CLASS = EntityIdentities::class;
    public const string COLLECTION_KEY = HilosDbContext::identities;

    /**
     * Finds the identity for a (type, identifier) pair.
     *
     * Canonical login/link lookup: identifier is unique per type, so this
     * returns at most one identity. Callers normalize identifier per type
     * (lowercase email, E.164 phone, 'provider:subject') before lookup.
     *
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return ?ObjectIdentity Identity object or null if not found
     * @throws DatabaseException If the database query fails
     */
    public function findByIdentity(string $type, string $identifier): ?ObjectIdentity
    {
        if ($type === '' || $identifier === '') {
            return null;
        }

        $entityIdentity = EntityIdentity::get([
            EntityIdentity::type => $type,
            EntityIdentity::identifier => $identifier,
        ])->first();

        if ($entityIdentity === null) {
            return null;
        }

        if (!isset($this->objects[$entityIdentity->id])) {
            $this->objects[$entityIdentity->id] = ObjectIdentity::fromEntity($entityIdentity);
        }

        return $this->objects[$entityIdentity->id];
    }

    /**
     * Creates a `password`-type identity for a user with a freshly hashed secret.
     *
     * Register write path of the identity layer (HIL-164), symmetric with the
     * verify/rehash primitives on {@see ObjectIdentity}: the plaintext is hashed
     * here and the hash is written with a targeted query, so the secret is minted
     * and stored entirely inside the layer and never reaches the ORM columns, the
     * object/view surface, or the cross-worker sync bus. Uniqueness is per
     * (type, identifier); a caller must lowercase the email before calling.
     *
     * The row is first inserted through the ORM (which carries the non-secret
     * columns and assigns the id) and the hash is then set with a follow-up
     * UPDATE, the same split the rehash primitive uses.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $plainSecret Plaintext password to hash and store
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When identifier or secret is empty
     * @throws DuplicateValueException When an identity already exists for (password, identifier)
     * @throws DatabaseException If the insert or secret write query fails
     */
    public function createPasswordIdentity(int $userId, string $identifier, string $plainSecret): ObjectIdentity
    {
        if ($identifier === '' || $plainSecret === '') {
            throw new EmptyValueException('Identity identifier and secret are required');
        }

        if ($this->findByIdentity(IdentityType::PASSWORD, $identifier) !== null) {
            throw new DuplicateValueException('email already used');
        }

        $identity = ObjectIdentity::create();
        $identity->userId = $userId;
        $identity->type = IdentityType::PASSWORD;
        $identity->identifier = $identifier;
        $identity->verified = false;
        $identity->sync();

        $id = $identity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(password_hash($plainSecret, PASSWORD_DEFAULT)));
        $params->add(SqlParam::int($id));
        Database::sql(
            'UPDATE `' . EntityIdentity::_table . '` SET `' . EntityIdentity::secret . '` = ? WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        );

        $this->objects[$id] = $identity;

        return $identity;
    }

    /**
     * Creates a verified `sms`-type identity for a user (HIL-280).
     *
     * The phone-login write path: unlike a password identity there is no secret —
     * possession of the one-time SMS code already proved the phone, so the row is
     * inserted with `secret = NULL` and `verified = true` and needs no follow-up
     * hash write. The identifier is a normalized E.164 phone (the caller
     * normalizes through {@see \Hilos\Auth\PhoneNumber::normalize()} before
     * calling); uniqueness is per (sms, identifier).
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized E.164 phone number
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (sms, identifier)
     * @throws DatabaseException If the insert query fails
     */
    public function createSmsIdentity(int $userId, string $identifier): ObjectIdentity
    {
        if ($identifier === '') {
            throw new EmptyValueException('Identity identifier is required');
        }

        if ($this->findByIdentity(IdentityType::SMS, $identifier) !== null) {
            throw new DuplicateValueException('phone already used');
        }

        $identity = ObjectIdentity::create();
        $identity->userId = $userId;
        $identity->type = IdentityType::SMS;
        $identity->identifier = $identifier;
        $identity->verified = true;
        $identity->sync();

        $id = $identity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        $this->objects[$id] = $identity;

        return $identity;
    }

    /**
     * Resolves the user id owning a verified identity for an email (HIL-283).
     *
     * The passwordless-login read accessor shared with the OAuth email-collision
     * link flow (HIL-282): it answers "which existing account owns this verified
     * email?" without disclosing anything to the caller beyond the id. Only email
     * identifiers can match (`password`/`magic_link` store the lowercased email;
     * `sms`/`oauth` identifiers are a phone / `provider:subject` and never equal an
     * email), so the lookup is by identifier across every type and keeps only a
     * verified, user-owning row. An unverified email or no account resolves to
     * null, letting the caller answer generically (anti-enumeration).
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of a verified email identity, or null when none
     * @throws DatabaseException If the database query fails
     */
    public function findUserIdByVerifiedEmail(string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        $entityIdentities = EntityIdentity::get([EntityIdentity::identifier => $email]);
        foreach ($entityIdentities as $entityIdentity) {
            if ($entityIdentity->verified && $entityIdentity->user_id !== null) {
                return $entityIdentity->user_id;
            }
        }

        return null;
    }

    /**
     * Lists all identities owned by a user.
     *
     * @param int $userId Owning user id
     * @return list<ObjectIdentity> Identity objects for the user (empty when none)
     * @throws DatabaseException If the database query fails
     */
    public function listByUser(int $userId): array
    {
        $entityIdentities = EntityIdentity::get([EntityIdentity::user_id => $userId]);

        $result = [];
        foreach ($entityIdentities as $entityIdentity) {
            if ($entityIdentity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entityIdentity->id])) {
                $this->objects[$entityIdentity->id] = ObjectIdentity::fromEntity($entityIdentity);
            }
            $result[] = $this->objects[$entityIdentity->id];
        }

        return $result;
    }
}
