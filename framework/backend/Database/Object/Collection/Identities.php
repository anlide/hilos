<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\PhoneNumber;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Identities as EntityIdentities;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Utils\Logger;

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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
            $this->hydrate($entityIdentity->id, ObjectIdentity::fromEntity($entityIdentity));
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
     * @throws DuplicateValueException When the address is taken, or the account already has a password
     * @throws DatabaseException If the insert or secret write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
     * Bulk-seed write path ({@see UserTestSeedCommand}):
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
     * THE place where "an account holds at most one password" is held (HIL-692). It is
     * one guard rather than three because every road that mints a password comes through
     * here - the registration, the profile's add-password, the test seed - and the rule
     * has to be a property of the write, not a habit of its callers. What made it a rule
     * is that a second secret is invisible from the surface and unremovable through it:
     * the profile changes the FIRST password row it finds, answers "password changed",
     * and the other one keeps letting someone in through the other address.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $passwordHash Precomputed `password_hash()` value to store as the secret
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When the address is taken, or the account already has a password
     * @throws DatabaseException If the insert or secret write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    public function createPasswordIdentityWithHash(int $userId, string $identifier, string $passwordHash): ObjectIdentity
    {
        if ($identifier === '') {
            throw new EmptyValueException('Identity identifier is required');
        }

        if ($this->findByIdentity(IdentityType::PASSWORD, $identifier) !== null) {
            throw new DuplicateValueException('email already used');
        }

        if ($this->findPasswordByUser($userId) !== null) {
            throw new DuplicateValueException('account already has a password');
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

        DbWriteGuard::guardItemWrite(static::COLLECTION_KEY, (string)$id, TruthSourceOperation::Update);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($passwordHash));
        $params->add(SqlParam::int($id));
        Database::sql(
            'UPDATE `' . EntityIdentity::_table . '` SET `' . EntityIdentity::secret . '` = ? WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        );

        $this[$id] = $identity;

        return $identity;
    }

    /**
     * Creates a verified `sms`-type identity for a user (HIL-280).
     *
     * The phone-login write path: unlike a password identity there is no secret —
     * possession of the one-time SMS code already proved the phone, so the row is
     * inserted with `secret = NULL` and `verified = true` and needs no follow-up
     * hash write. The identifier is a normalized E.164 phone (the caller
     * normalizes through {@see PhoneNumber::normalize()} before
     * calling); uniqueness is per (sms, identifier).
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized E.164 phone number
     * @return ObjectIdentity The created identity object
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (sms, identifier)
     * @throws DatabaseException If the insert query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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

        $this[$id] = $identity;

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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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

        $this[$id] = $identity;

        return $identity;
    }

    /**
     * Creates a verified `passkey`-type identity anchor for a user (HIL-284).
     *
     * The WebAuthn write path: a passkey is a thin anchor row here plus a crypto
     * row in `hilos_passkey_credential`
     * ({@see PasskeyCredentials::createFromRegistration()}).
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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

        $this[$id] = $identity;

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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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

        $this[$id] = $identity;

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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
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
            $this->hydrate($identityId, ObjectIdentity::fromEntity($entityIdentity));
        }

        $this->objects[$identityId]->delete();
        unset($this[$identityId]);
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
     * The passwords are settled BEFORE anything moves (HIL-692), because moving them
     * first would build the very state the one-password rule forbids and then ask this
     * method to unbuild it. `$passwordFate` has no default on purpose: an account merge
     * has to name what happens to a password even when the answer is "nothing to name",
     * and a default would let the one caller that forgot look exactly like the ones that
     * decided. Null is that "nothing to name" and is only legal when at most one of the
     * two accounts has a password - {@see passwordFateNeeded()} is how a caller asks
     * that in time to refuse in its own words.
     *
     * @param int $fromUserId Loser user id whose identities are absorbed
     * @param int $toUserId Survivor user id that receives the identities
     * @param ?PasswordFate $passwordFate Whose password stays, or null when neither pair of accounts holds two
     * @return int Number of identities re-pointed to the survivor
     * @throws LogicException When both accounts hold a password and no fate was named
     * @throws DatabaseException If a lookup, move, demote, or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    public function rePointToUser(int $fromUserId, int $toUserId, ?PasswordFate $passwordFate): int
    {
        $this->settlePasswords($fromUserId, $toUserId, $passwordFate);

        $moved = 0;
        foreach ($this->listByUser($fromUserId) as $identity) {
            $existing = $this->findByIdentity($identity->type, $identity->identifier);
            if ($existing !== null && $existing->userId === $toUserId) {
                $identity->delete();
                if ($identity->id !== null) {
                    unset($this[$identity->id]);
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
     * Whether a merge of these two accounts has to be told whose password stays (HIL-692).
     *
     * The question a merge asks its operator BEFORE it opens a transaction: only two
     * passwords force a choice, and while at most one exists it survives on its own and
     * the command keeps the shape it always had. Asked of the accounts rather than of
     * the addresses, which is the whole rule in one line.
     *
     * @param int $fromUserId Loser user id whose identities would be absorbed
     * @param int $toUserId Survivor user id that would receive them
     * @return bool True when both accounts hold a password and one of them must give way
     * @throws DatabaseException If a lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function passwordFateNeeded(int $fromUserId, int $toUserId): bool
    {
        return $this->findPasswordByUser($fromUserId) !== null
            && $this->findPasswordByUser($toUserId) !== null;
    }

    /**
     * Resolves an account's one password identity (HIL-692).
     *
     * The read the one-password rule makes possible, and the one every password flow now
     * asks: an address names the PERSON, and the secret checked against it is theirs, not
     * the address's. Signing in, resetting, and the write guard all go through here, so
     * "the account's password" has a single meaning wherever it is said.
     *
     * Data predating the rule is read deterministically rather than refused: the lowest
     * id wins and the violation is logged, so exactly one secret opens the account and
     * the extra one stops letting anybody in - which is why the leaf carries no migration
     * for such accounts. Reads over {@see listByUser()} rather than a query of its own,
     * so the objects it hands back are the collection's own and a write through them is
     * seen by everyone holding the same row.
     *
     * @param int $userId Owning user id
     * @return ?ObjectIdentity The account's password identity, or null when it has none
     * @throws DatabaseException If a lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findPasswordByUser(int $userId): ?ObjectIdentity
    {
        $password = null;
        $passwordId = null;
        $held = 0;

        foreach ($this->listByUser($userId) as $identity) {
            $id = $identity->id;
            if ($identity->type !== IdentityType::PASSWORD || $id === null) {
                continue;
            }

            $held++;
            if ($passwordId === null || $id < $passwordId) {
                $password = $identity;
                $passwordId = $id;
            }
        }

        if ($held > 1) {
            Logger::error('Account holds more than one password identity', [
                'userId' => $userId,
                'held' => $held,
                'usedIdentityId' => $passwordId,
            ]);
        }

        return $password;
    }

    /**
     * Turns a password identity into a plain carrier of its address (HIL-692).
     *
     * What "this password does not stay" means mechanically, and it is deliberately not
     * "a password row with an empty secret": a row is counted by its TYPE
     * ({@see IdentifierDetector}), so an emptied password row would keep offering a
     * password field that nothing can answer - the exact dead end the one-password rule
     * exists to close. The row becomes a `magic_link` for the same address and keeps its
     * `verified` flag, so the address stays proven and stays the person's.
     *
     * The secret is erased before the type flips, so no window exists in which a
     * `magic_link` row carries one. Should a `magic_link` for that address already exist,
     * the row is deleted instead - (type, identifier) is globally unique and the address
     * is already carried by the row that is there, which is the same reasoning
     * {@see rePointToUser()} uses for a duplicate it declines to move.
     *
     * @param ObjectIdentity $identity Password identity that did not survive the merge
     * @throws DatabaseException If a lookup, erase, delete, or update query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    public function demotePasswordToMagicLink(ObjectIdentity $identity): void
    {
        if ($this->findByIdentity(IdentityType::MAGIC_LINK, $identity->identifier) !== null) {
            $id = $identity->id;
            $identity->delete();
            if ($id !== null) {
                unset($this[$id]);
            }

            return;
        }

        $identity->clearPassword();
        $identity->type = IdentityType::MAGIC_LINK;
        $identity->sync();
    }

    /**
     * Applies the named fate to the two accounts' passwords, before any identity moves.
     *
     * One reading of the name for all three cases, and no refusals of its own: the value
     * says whose password STAYS, so the other one is demoted whether or not the account
     * naming it has a password of its own. "Keep the survivor's, and the survivor has
     * none" is an outcome the operator is entitled to ask for, not a typo to reject.
     *
     * @param int $fromUserId Loser user id whose identities are absorbed
     * @param int $toUserId Survivor user id that receives them
     * @param ?PasswordFate $passwordFate Whose password stays, or null when nobody had to choose
     * @throws LogicException When both accounts hold a password and no fate was named
     * @throws DatabaseException If a lookup, erase, delete, or update query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    private function settlePasswords(int $fromUserId, int $toUserId, ?PasswordFate $passwordFate): void
    {
        $loserPassword = $this->findPasswordByUser($fromUserId);
        $survivorPassword = $this->findPasswordByUser($toUserId);

        if ($passwordFate === null) {
            if ($loserPassword !== null && $survivorPassword !== null) {
                throw new LogicException('Merging two accounts with a password each needs a password fate');
            }

            return;
        }

        if ($survivorPassword !== null && $passwordFate !== PasswordFate::SURVIVOR) {
            $this->demotePasswordToMagicLink($survivorPassword);
        }

        if ($loserPassword !== null && $passwordFate !== PasswordFate::LOSER) {
            $this->demotePasswordToMagicLink($loserPassword);
        }
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * Resolves the account an email already belongs to, by any road (HIL-608).
     *
     * THE definition of "this address is taken", and the only one: a `password`
     * identity whether or not it is verified (it is signed in with either way), or a
     * verified identity of any other type. Every caller that used to spell the pair
     * out for itself asks this instead - the registration submit, the confirm, the
     * magic-link send, and the identifier lookup - because while there were several
     * spellings they disagreed, and on an address carrying an UNVERIFIED password
     * identity the disagreement built a second account for the same person.
     *
     * It sits between its two neighbours and is not either of them.
     * {@see findUserIdByVerifiedEmail()} answers "who has PROVEN this address" and is
     * what an email-proof flow signs in; {@see findUserIdByEmail()} answers "does any
     * row mention it" and is for callers whose proof is the credential itself. This
     * one answers "may a registration start here", which is the union of the first
     * with the unverified password rows - the accounts that exist and can be signed
     * into, though nobody has answered their mail.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id when the address is somebody's, or null when it is free
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findAccountIdByEmail(string $email): ?int
    {
        return $this->findByIdentity(IdentityType::PASSWORD, $email)?->userId
            ?? $this->findUserIdByVerifiedEmail($email);
    }

    /**
     * Resolves the user id owning any identity for an email, verified or not (HIL-284).
     *
     * The verification-agnostic sibling of {@see findUserIdByVerifiedEmail()}, for
     * callers where the credential itself is the proof of identity rather than the
     * email. Because email confirmation is not wired for password registration,
     * those accounts stay `verified = false`, so the verified-only resolver would
     * strand every such caller. Do NOT use this for email-proof flows (magic-link, OAuth email
     * collision) — those must keep {@see findUserIdByVerifiedEmail()}, and do NOT use it to
     * ask whether an address is free — that is {@see findAccountIdByEmail()}, which counts a
     * `password` row of any state but ignores an unverified row of another type. The three
     * differ only in which rows they accept, which is exactly why each names the question it
     * answers. Only email identifiers can match (`sms`/`oauth` identifiers are a phone /
     * `provider:subject` and never equal an email); no account resolves to null.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of any email identity, or null when none
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
                $this->hydrate($entityIdentity->id, ObjectIdentity::fromEntity($entityIdentity));
            }
            $result[] = $this->objects[$entityIdentity->id];
        }

        return $result;
    }
}
