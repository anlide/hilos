<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
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
     * Register write path of the identity layer (HIL-164): a thin wrapper that hashes
     * the plaintext with {@see PASSWORD_DEFAULT} and delegates to
     * {@see createPasswordIdentityWithHash()}, which owns the write. Existing callers
     * (registration, etc.) keep their contract — secret in, hashed and stored inside
     * the layer. A caller must lowercase the email before calling.
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
        if ($plainSecret === '') {
            throw new EmptyValueException('Identity identifier and secret are required');
        }

        return $this->createPasswordIdentityWithHash($userId, $identifier, password_hash($plainSecret, PASSWORD_DEFAULT));
    }

    /**
     * Creates an unverified `password`-type identity from a precomputed hash.
     *
     * Bulk-seed write path ({@see \Hilos\Core\CLI\Commands\UserTestSeedCommand}):
     * identical to {@see createPasswordIdentity()} except the caller supplies the
     * already-computed `password_hash()` value, so a fixture that seeds many users pays
     * the bcrypt cost once and reuses the hash for all of them. Symmetric with the
     * verify/rehash primitives on {@see ObjectIdentity}: the row is first inserted
     * through the ORM (which carries the non-secret columns and assigns the id) and the
     * hash is then written with a follow-up parameterized UPDATE, the same split the
     * rehash primitive uses, so the secret never reaches the ORM columns, the
     * object/view surface, or the cross-worker sync bus. `verified` stays false, exactly
     * as after a real password registration. Uniqueness is per (type, identifier); a
     * caller must lowercase the email before calling.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $passwordHash Precomputed `password_hash()` value to store as the secret
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (password, identifier)
     * @throws DatabaseException If the insert or secret write query fails
     */
    public function createPasswordIdentityWithHash(int $userId, string $identifier, string $passwordHash): ObjectIdentity
    {
        if ($identifier === '') {
            throw new EmptyValueException('Identity identifier is required');
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
        $params->add(SqlParam::string($passwordHash));
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
     * Creates a verified `oauth`-type identity for a user (HIL-281).
     *
     * The external-login write path: like the SMS identity there is no secret —
     * the provider already vouched for the account — so the row is inserted with
     * `secret = NULL` and `verified = true` and needs no follow-up write. The
     * identifier is the canonical `provider:subject` pair and the `provider`
     * column records the provider key on its own; uniqueness is per
     * (oauth, identifier). Account resolution keys strictly on (provider, subject)
     * — email is never consulted here (the collision/merge policy is HIL-282).
     *
     * @param int $userId Owning user id
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $subject Provider-immutable account subject id
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When provider or subject is empty
     * @throws DuplicateValueException When an identity already exists for (oauth, identifier)
     * @throws DatabaseException If the insert query fails
     */
    public function createOauthIdentity(int $userId, string $provider, string $subject): ObjectIdentity
    {
        if ($provider === '' || $subject === '') {
            throw new EmptyValueException('Identity provider and subject are required');
        }

        $identifier = $provider . ':' . $subject;

        if ($this->findByIdentity(IdentityType::OAUTH, $identifier) !== null) {
            throw new DuplicateValueException('oauth account already linked');
        }

        $identity = ObjectIdentity::create();
        $identity->userId = $userId;
        $identity->type = IdentityType::OAUTH;
        $identity->identifier = $identifier;
        $identity->provider = $provider;
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
     * Creates a verified `passkey`-type identity anchor for a user (HIL-284).
     *
     * The WebAuthn write path: a passkey is a thin anchor row here plus a crypto
     * row in `hilos_passkey_credential`
     * ({@see \Hilos\Database\Object\Collection\PasskeyCredentials::createFromRegistration()}).
     * Like the SMS/OAuth identities there is no secret — the attestation ceremony
     * already proved possession of the authenticator — so the row is inserted with
     * `secret = NULL` and `verified = true` and needs no follow-up write. The
     * identifier is the authenticator's base64url credential id; uniqueness is per
     * (passkey, identifier), which the credential id already guarantees.
     *
     * @param int $userId Owning user id
     * @param string $credentialId Base64url credential id from the authenticator
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When credential id is empty
     * @throws DuplicateValueException When an identity already exists for (passkey, identifier)
     * @throws DatabaseException If the insert query fails
     */
    public function createPasskeyIdentity(int $userId, string $credentialId): ObjectIdentity
    {
        if ($credentialId === '') {
            throw new EmptyValueException('Identity identifier is required');
        }

        if ($this->findByIdentity(IdentityType::PASSKEY, $credentialId) !== null) {
            throw new DuplicateValueException('passkey already registered');
        }

        $identity = ObjectIdentity::create();
        $identity->userId = $userId;
        $identity->type = IdentityType::PASSKEY;
        $identity->identifier = $credentialId;
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
     * Creates a verified `magic_link`-type identity for a user (HIL-405).
     *
     * The OAuth sign-up email-persist path: when an identity provider vouches for a
     * verified email, that email is also recorded as a proven email identity so
     * {@see findVerifiedEmailByUser()} resolves it (and, as an accepted consequence,
     * magic-link login on that email becomes available). Like the SMS/OAuth
     * identities there is no secret — the provider already proved the email — so the
     * row is inserted with `secret = NULL` and `verified = true`. The identifier is
     * the lowercased, trimmed email normalized here so the stored value is canonical
     * regardless of caller; uniqueness is per (magic_link, identifier).
     *
     * @param int $userId Owning user id
     * @param string $email Provider-supplied verified email (normalized here)
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When the normalized email is empty
     * @throws DuplicateValueException When an identity already exists for (magic_link, identifier)
     * @throws DatabaseException If the insert query fails
     */
    public function createMagicLinkIdentity(int $userId, string $email): ObjectIdentity
    {
        $identifier = mb_strtolower(trim($email));
        if ($identifier === '') {
            throw new EmptyValueException('Identity identifier is required');
        }

        if ($this->findByIdentity(IdentityType::MAGIC_LINK, $identifier) !== null) {
            throw new DuplicateValueException('email already used');
        }

        $identity = ObjectIdentity::create();
        $identity->userId = $userId;
        $identity->type = IdentityType::MAGIC_LINK;
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
     * Hard-deletes one of a user's identities (HIL-377).
     *
     * The profile unlink write path, symmetric with the create primitives:
     * server-authoritative guards live here so the delete is safe regardless of
     * caller. The lookup is by id; a missing row is an idempotent no-op (a
     * concurrent unlink already removed it). Two guards protect the row: the
     * caller may only unlink an identity they own, and the last remaining
     * identity is refused so a user is never left with zero sign-in methods. The
     * physical delete goes through the object so a DB_SYNC_DELETED broadcast
     * re-emits the owner's identities projection to all of their connections.
     *
     * @param int $userId Owning user id (session user)
     * @param int $identityId Identity id to unlink
     * @throws ValidationException When the identity is not owned by the user, or is their last one
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function deleteIdentity(int $userId, int $identityId): void
    {
        $entityIdentity = EntityIdentity::get([EntityIdentity::id => $identityId])->first();
        if ($entityIdentity === null) {
            return;
        }

        if ($entityIdentity->user_id !== $userId) {
            throw new ValidationException('cannot unlink an identity you do not own');
        }

        if (count($this->listByUser($userId)) <= 1) {
            throw new ValidationException('cannot remove your only sign-in method');
        }

        if (!isset($this->objects[$identityId])) {
            $this->objects[$identityId] = ObjectIdentity::fromEntity($entityIdentity);
        }

        $this->objects[$identityId]->delete();
        unset($this->objects[$identityId]);
    }

    /**
     * Re-points every identity owned by a loser user to a survivor user (HIL-378).
     *
     * Account-merge write path: the survivor absorbs the loser's sign-in
     * methods. Each identity keeps its (type, identifier) and only its owner
     * changes, so a move is a targeted user_id write broadcast through the object
     * (a DB_SYNC event re-emits the survivor's identities projection). A link is
     * never moved onto a duplicate: (type, identifier) is globally unique, so
     * should the survivor already own an identical pair the loser's row is
     * hard-deleted rather than moved (HIL-282 "never move a link"). Returns the
     * number of identities actually moved; dropped duplicates are not counted.
     *
     * @param int $fromUserId Loser user id whose identities are absorbed
     * @param int $toUserId Survivor user id that receives the identities
     * @return int Number of identities re-pointed to the survivor
     * @throws DatabaseException If a lookup, move, or delete query fails
     */
    public function rePointToUser(int $fromUserId, int $toUserId): int
    {
        $moved = 0;
        foreach ($this->listByUser($fromUserId) as $identity) {
            $existing = $this->findByIdentity($identity->type, $identity->identifier);
            if ($existing !== null && $existing->userId === $toUserId) {
                $identity->delete();
                if ($identity->id !== null) {
                    unset($this->objects[$identity->id]);
                }
                continue;
            }

            $identity->userId = $toUserId;
            $identity->sync();
            $moved++;
        }

        return $moved;
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
     * Resolves the user id owning any identity for an email, verified or not (HIL-284).
     *
     * The verification-agnostic sibling of {@see findUserIdByVerifiedEmail()}, for
     * callers where the credential itself is the proof of identity rather than the
     * email — passkey username-first login, whose WebAuthn assertion authenticates
     * the account and only needs the email to scope allowCredentials. Because email
     * confirmation is not wired for password registration, those accounts stay
     * `verified = false`, so the verified-only resolver would strand every passkey
     * login. Do NOT use this for email-proof flows (magic-link, OAuth email
     * collision) — those must keep {@see findUserIdByVerifiedEmail()}. Only email
     * identifiers can match (`sms`/`oauth` identifiers are a phone / `provider:subject`
     * and never equal an email); no account resolves to null.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of any email identity, or null when none
     * @throws DatabaseException If the database query fails
     */
    public function findUserIdByEmail(string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        $entityIdentities = EntityIdentity::get([EntityIdentity::identifier => $email]);
        foreach ($entityIdentities as $entityIdentity) {
            if ($entityIdentity->user_id !== null) {
                return $entityIdentity->user_id;
            }
        }

        return null;
    }

    /**
     * Resolves the email of a user's first verified email-bearing identity (HIL-402).
     *
     * The inverse of {@see findUserIdByVerifiedEmail()}: given the session user, it
     * answers "which proven email can a password attach to?" for the profile
     * add-password flow (and, reusably, HIL-405). Only `password`/`magic_link`
     * identifiers are an email; `sms`/`oauth`/`passkey` identifiers are a phone /
     * `provider:subject` / credential id and are skipped. Returns the identifier of
     * the first verified email-bearing identity, or null when the user has none
     * (SMS-only or legacy OAuth, routed to the email-code branch in HIL-406).
     *
     * @param int $userId Owning user id
     * @return ?string Lowercased email of a verified email-bearing identity, or null when none
     * @throws DatabaseException If the database query fails
     */
    public function findVerifiedEmailByUser(int $userId): ?string
    {
        $entityIdentities = EntityIdentity::get([EntityIdentity::user_id => $userId]);
        foreach ($entityIdentities as $entityIdentity) {
            if (
                $entityIdentity->verified
                && in_array($entityIdentity->type, [IdentityType::PASSWORD, IdentityType::MAGIC_LINK], true)
            ) {
                return $entityIdentity->identifier;
            }
        }

        return null;
    }

    /**
     * Resolves the number of a user's first verified `sms` identity (HIL-285).
     *
     * The SMS analogue of {@see findVerifiedEmailByUser()}: given the recipient user, it
     * answers "which proven number can an SMS reach?" for the SMS delivery channel. Only a
     * verified `sms` identity is an address - an unverified number is never used (SMS to an
     * unproven number is both a leak and a cost). Returns the identifier (E.164) of the first
     * verified `sms` identity, or null when the user has none.
     *
     * @param int $userId Owning user id
     * @return ?string E.164 number of a verified `sms` identity, or null when none
     * @throws DatabaseException If the database query fails
     */
    public function findVerifiedSmsByUser(int $userId): ?string
    {
        $entityIdentities = EntityIdentity::get([EntityIdentity::user_id => $userId]);
        foreach ($entityIdentities as $entityIdentity) {
            if ($entityIdentity->verified && $entityIdentity->type === IdentityType::SMS) {
                return $entityIdentity->identifier;
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
