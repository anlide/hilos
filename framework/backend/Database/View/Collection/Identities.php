<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Identity;

/**
 * Identities Db collection.
 *
 * Read-facing API for the framework-owned hilos_identity table. Write actions
 * (create / delete / verify-flip / secret update) are added by the consuming
 * auth leaves; the register leaf (HIL-164) adds {@see createPasswordIdentity()},
 * delegating the hash-at-rest insert to the object collection.
 *
 * @extends DbCollection<Identity, ObjectIdentities>
 */
final class Identities extends DbCollection
{
    public const string DB_ITEM_CLASS = Identity::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectIdentities::class;

    /**
     * Finds the identity for a (type, identifier) pair.
     *
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return ?Identity Identity Db item or null if not found
     * @throws DatabaseException On database error while resolving the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findByIdentity(string $type, string $identifier): ?Identity
    {
        $objectIdentity = $this->objectCollection->findByIdentity($type, $identifier);

        if ($objectIdentity?->id === null) {
            return null;
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($objectIdentity->id);
        return $identity;
    }

    /**
     * Resolves the email of a user's first verified email-bearing identity (HIL-402).
     *
     * Add-password read accessor for the profile: delegates to the object collection's
     * {@see ObjectIdentities::findVerifiedEmailByUser()} primitive, which answers "which
     * proven email can a new password identity attach to?" for the session user. Null
     * when the user has no verified email (SMS-only / legacy OAuth → HIL-406).
     *
     * @param int $userId Owning user id
     * @return ?string Lowercased email of a verified email-bearing identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findVerifiedEmailByUser(int $userId): ?string
    {
        return $this->objectCollection->findVerifiedEmailByUser($userId);
    }

    /**
     * Resolves the number of a user's first verified `sms` identity (HIL-285).
     *
     * SMS delivery-channel read accessor: delegates to the object collection's
     * {@see ObjectIdentities::findVerifiedSmsByUser()} primitive, which answers "which proven
     * number can an SMS reach?" for the recipient user. Null when the user has no verified
     * `sms` identity.
     *
     * @param int $userId Owning user id
     * @return ?string E.164 number of a verified `sms` identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findVerifiedSmsByUser(int $userId): ?string
    {
        return $this->objectCollection->findVerifiedSmsByUser($userId);
    }

    /**
     * Lists all identities owned by a user.
     *
     * @param int $userId Owning user id
     * @return list<Identity> Identity Db items for the user (empty when none)
     * @throws DatabaseException On database error while loading the identities
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function listByUser(int $userId): array
    {
        $objectIdentities = $this->objectCollection->listByUser($userId);

        $result = [];
        foreach ($objectIdentities as $objectIdentity) {
            if ($objectIdentity->id === null) {
                continue;
            }
            $item = $this->getItemForKey($objectIdentity->id);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Resolves an account's one password identity (HIL-692).
     *
     * The read every password flow asks now: delegates to the object collection's
     * {@see ObjectIdentities::findPasswordByUser()} primitive, which answers "which
     * secret is THIS PERSON's?" — an address only names them. Null when the account has
     * no password: a person who has only ever used a link, a provider or a phone.
     *
     * @param int $userId Owning user id
     * @return ?Identity The account's password identity as a read-facing Db item, or null when it has none
     * @throws DatabaseException On database error while resolving the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findPasswordByUser(int $userId): ?Identity
    {
        $objectIdentity = $this->objectCollection->findPasswordByUser($userId);

        if ($objectIdentity?->id === null) {
            return null;
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($objectIdentity->id);
        return $identity;
    }

    /**
     * Resolves the user id owning a verified identity for an email (HIL-283).
     *
     * Passwordless-login read accessor shared with the OAuth email-collision
     * link flow (HIL-282): delegates to the object collection's
     * {@see ObjectIdentities::findUserIdByVerifiedEmail()} primitive, which
     * answers "which existing account owns this verified email?" without
     * disclosing anything to the caller beyond the id (anti-enumeration).
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of a verified email identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findUserIdByVerifiedEmail(string $email): ?int
    {
        return $this->objectCollection->findUserIdByVerifiedEmail($email);
    }

    /**
     * Resolves the account an email already belongs to, by any road (HIL-608).
     *
     * THE definition of "this address is taken" on the read-facing side: delegates to
     * the object collection's {@see ObjectIdentities::findAccountIdByEmail()} primitive,
     * which counts a `password` identity whether or not it is verified and a verified
     * identity of any other type. It is the question a registration submit asks before
     * holding an address, and the one the identifier lookup asks about the same address -
     * asked through one method so the two can no longer answer differently.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id when the address is somebody's, or null when it is free
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findAccountIdByEmail(string $email): ?int
    {
        return $this->objectCollection->findAccountIdByEmail($email);
    }

    /**
     * Resolves the user id owning any identity for an email, verified or not (HIL-284).
     *
     * Verification-agnostic sibling of {@see findUserIdByVerifiedEmail()}: delegates
     * to the object collection's {@see ObjectIdentities::findUserIdByEmail()}
     * primitive, for callers where a credential rather than the email is the proof
     * of identity. Not for email-proof flows (magic-link / OAuth) — those keep the
     * verified resolver.
     *
     * SCAFFOLD: no caller since HIL-418 retired the username-first passkey login,
     * which resolved the account by email to scope allowCredentials. Kept because
     * the distinction it draws is a property of the identity model, not of that
     * one flow — the identifier lookup of HIL-414 asks the same question.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of any email identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findUserIdByEmail(string $email): ?int
    {
        return $this->objectCollection->findUserIdByEmail($email);
    }

    /**
     * Creates a `password`-type identity for a user with a freshly hashed secret.
     *
     * Register write path of the identity layer (HIL-164): delegates the hash-at-rest
     * insert to the object collection's {@see ObjectIdentities::createPasswordIdentity()}
     * primitive and returns the read-facing view item for the new row. The secret is
     * minted and stored entirely inside the object layer and never crosses this
     * read-facing boundary, mirroring the delegation in {@see findByIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $plainSecret Plaintext password to hash and store
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When identifier or secret is empty
     * @throws DuplicateValueException When an identity already exists for (password, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createPasswordIdentity(int $userId, string $identifier, string $plainSecret): Identity
    {
        $objectIdentity = $this->objectCollection->createPasswordIdentity($userId, $identifier, $plainSecret);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates an unverified `password`-type identity from a precomputed hash (HIL-327).
     *
     * Bulk-seed write path of {@see UserTestSeedCommand}: the
     * read-facing sibling of {@see createPasswordIdentity()} that lets a fixture seeding
     * many users pay the bcrypt cost once and reuse the hash. Delegates the hash-at-rest
     * insert to the object collection's
     * {@see ObjectIdentities::createPasswordIdentityWithHash()} primitive and returns the
     * read-facing view item for the new row. The hash is passed straight through and
     * never re-crosses this boundary, mirroring the delegation in
     * {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $passwordHash Precomputed `password_hash()` value to store as the secret
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (password, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createPasswordIdentityWithHash(int $userId, string $identifier, string $passwordHash): Identity
    {
        $objectIdentity = $this->objectCollection->createPasswordIdentityWithHash($userId, $identifier, $passwordHash);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates a verified `oauth`-type identity for a user (HIL-281).
     *
     * External-login write path of the identity layer: delegates the
     * secret-less, verified insert to the object collection's
     * {@see ObjectIdentities::createOauthIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is the canonical
     * `provider:subject` pair; account resolution keys strictly on
     * (provider, subject).
     *
     * @param int $userId Owning user id
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $subject Provider-immutable account subject id
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When provider or subject is empty
     * @throws DuplicateValueException When an identity already exists for (oauth, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createOauthIdentity(int $userId, string $provider, string $subject): Identity
    {
        $objectIdentity = $this->objectCollection->createOauthIdentity($userId, $provider, $subject);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates a verified `sms`-type identity for a user (HIL-280).
     *
     * Phone-login write path of the identity layer: delegates the secret-less,
     * verified insert to the object collection's
     * {@see ObjectIdentities::createSmsIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is a normalized
     * E.164 phone; uniqueness is per (sms, identifier), mirroring the delegation
     * in {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized E.164 phone number
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (sms, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createSmsIdentity(int $userId, string $identifier): Identity
    {
        $objectIdentity = $this->objectCollection->createSmsIdentity($userId, $identifier);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates a verified `magic_link`-type identity for a user (HIL-405).
     *
     * OAuth email-persist write path of the identity layer: delegates the
     * secret-less, verified insert to the object collection's
     * {@see ObjectIdentities::createMagicLinkIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is the provider's
     * verified email (lowercased and trimmed in the primitive); uniqueness is per
     * (magic_link, identifier), mirroring the delegation in
     * {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $email Provider-supplied verified email (normalized in the primitive)
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When the normalized email is empty
     * @throws DuplicateValueException When an identity already exists for (magic_link, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createMagicLinkIdentity(int $userId, string $email): Identity
    {
        $objectIdentity = $this->objectCollection->createMagicLinkIdentity($userId, $email);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates a verified `passkey`-type identity anchor for a user (HIL-284).
     *
     * WebAuthn write path of the identity layer: delegates the secret-less,
     * verified insert to the object collection's
     * {@see ObjectIdentities::createPasskeyIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is the
     * authenticator's base64url credential id; uniqueness is per
     * (passkey, identifier), mirroring the delegation in
     * {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $credentialId Base64url credential id from the authenticator
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When credential id is empty
     * @throws DuplicateValueException When an identity already exists for (passkey, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createPasskeyIdentity(int $userId, string $credentialId): Identity
    {
        $objectIdentity = $this->objectCollection->createPasskeyIdentity($userId, $credentialId);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Hard-deletes one of a user's identities (HIL-377).
     *
     * Profile unlink write path: delegates to the object collection's
     * {@see ObjectIdentities::deleteIdentity()} primitive, which owns the
     * ownership and last-identity guards and the (id, user_id)-scoped delete.
     *
     * Since HIL-722 the primitive also refuses a passkey anchor whose stored
     * credential is still there, because taking the anchor alone leaves a key that
     * keeps signing its owner in. A caller reaching this door for a passkey wants
     * the unlink command instead, which removes both halves in the safe order.
     *
     * @param int $userId Owning user id (session user)
     * @param int $identityId Identity id to unlink
     * @throws LogicException When a passkey identity is deleted directly while its credential is still stored
     * @throws ValidationException When the identity is not owned by the user, or is their last one
     * @throws DatabaseException On database error while deleting the identity
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteIdentity(int $userId, int $identityId): void
    {
        $this->objectCollection->deleteIdentity($userId, $identityId);
    }

    /**
     * Re-points every identity owned by a loser user to a survivor user (HIL-378).
     *
     * Account-merge write path: delegates to the object collection's
     * {@see ObjectIdentities::rePointToUser()} primitive, which owns the
     * duplicate-drop guard, the password settlement, and the targeted user_id move.
     *
     * `$passwordFate` is passed through without a default of its own (HIL-692) — the
     * write-path parity guard only watches the `create*` methods, so a default invented
     * here would diverge from the object collection silently and hand every caller on
     * this side an answer nobody chose.
     *
     * @param int $fromUserId Loser user id whose identities are absorbed
     * @param int $toUserId Survivor user id that receives the identities
     * @param ?PasswordFate $passwordFate Whose password stays, or null when neither account pair holds two
     * @return int Number of identities re-pointed to the survivor
     * @throws LogicException When both accounts hold a password and no fate was named
     * @throws DatabaseException On database error while re-pointing the identities
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function rePointToUser(int $fromUserId, int $toUserId, ?PasswordFate $passwordFate): int
    {
        return $this->objectCollection->rePointToUser($fromUserId, $toUserId, $passwordFate);
    }

    /**
     * Whether a merge of these two accounts has to be told whose password stays (HIL-692).
     *
     * Merge-guard read accessor: delegates to the object collection's
     * {@see ObjectIdentities::passwordFateNeeded()} primitive, which answers "do both
     * accounts hold a password?" — the one case where a merge cannot decide for itself.
     * A caller asks it before opening its transaction, so the refusal is worded by the
     * surface that has the operator's attention.
     *
     * @param int $fromUserId Loser user id whose identities would be absorbed
     * @param int $toUserId Survivor user id that would receive them
     * @return bool True when both accounts hold a password and one of them must give way
     * @throws DatabaseException On database error while resolving the identities
     */
    public function passwordFateNeeded(int $fromUserId, int $toUserId): bool
    {
        return $this->objectCollection->passwordFateNeeded($fromUserId, $toUserId);
    }

    /**
     * Runs a throwaway password verification to equalize login response time.
     *
     * Anti-enumeration companion of {@see findByIdentity()}: when a login lookup
     * misses, the handler still spends the bcrypt cost so response time does not
     * disclose whether the identifier exists.
     *
     * @param string $plainPassword Submitted plaintext to verify against a dummy hash
     */
    public function verifyDummyPassword(string $plainPassword): void
    {
        ObjectIdentity::verifyDummyPassword($plainPassword);
    }
}
