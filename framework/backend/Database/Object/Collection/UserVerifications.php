<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\UserVerifications as EntityUserVerifications;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserVerifications object collection.
 *
 * Persistence primitives of the verification layer (HIL-365): mint a challenge,
 * find the single active challenge for a (type, identifier), and void prior
 * active challenges. The orchestration (throttle, delivery, attempt handling)
 * lives in {@see \Hilos\Auth\Verification\VerificationService}; the code hash is
 * minted here with a targeted query so it never reaches the ORM columns.
 *
 * @extends Objects<ObjectUserVerification>
 * @method ObjectUserVerification|null current()
 * @method ObjectUserVerification|null first()
 * @method ObjectUserVerification|null last()
 * @method ObjectUserVerification|null get(int|string $key)
 * @method ObjectUserVerification|null offsetGet(mixed $offset)
 */
final class UserVerifications extends Objects
{
    public const string OBJECT_CLASS = ObjectUserVerification::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserVerifications::class;
    public const string COLLECTION_KEY = HilosDbContext::verifications;

    /**
     * Finds the single active challenge for a (type, identifier) pair.
     *
     * Active = unconsumed, unexpired, under the attempt ceiling. When more than
     * one active row exists (should not happen once issuing voids priors), the
     * newest (highest id) wins.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $maxAttempts Attempt ceiling excluding exhausted challenges
     * @return ?ObjectUserVerification Active challenge or null when none
     * @throws DatabaseException If the database query fails
     */
    public function findActive(string $type, string $identifier, int $maxAttempts): ?ObjectUserVerification
    {
        if ($type === '' || $identifier === '') {
            return null;
        }

        $now = TimeHelper::getSqlDateTime();
        $active = null;
        foreach ($this->hydrateByIdentity($type, $identifier) as $verification) {
            if (!$verification->isActive($now, $maxAttempts)) {
                continue;
            }
            if ($active === null || (int)$verification->id > (int)$active->id) {
                $active = $verification;
            }
        }

        return $active;
    }

    /**
     * Whether an active challenge was issued within the resend cooldown.
     *
     * Throttle guard for issuing: a fresh request is suppressed while a prior
     * challenge is still young, so a caller cannot spam delivery for a (type,
     * identifier).
     *
     * The challenge age is read off `expires_at` (which equals issue time + TTL,
     * both known) rather than a mapped `created_at`, so the row stays as lean as
     * the identity table: a challenge is "recent" when it still has more than
     * (TTL − cooldown) of life left.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $maxAttempts Attempt ceiling excluding exhausted challenges
     * @param int $cooldownSeconds Minimum age before a new challenge may issue
     * @param int $ttlSeconds Challenge time-to-live used to derive issue time from expiry
     * @return bool True when a recent active challenge exists
     * @throws DatabaseException If the database query fails
     */
    public function hasRecentActive(
        string $type,
        string $identifier,
        int $maxAttempts,
        int $cooldownSeconds,
        int $ttlSeconds,
    ): bool {
        if ($type === '' || $identifier === '') {
            return false;
        }

        $now = TimeHelper::getSqlDateTime();
        $recentThreshold = date('Y-m-d H:i:s', time() + $ttlSeconds - $cooldownSeconds);
        foreach ($this->hydrateByIdentity($type, $identifier) as $verification) {
            if ($verification->isActive($now, $maxAttempts) && $verification->expiresAt > $recentThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mints a new challenge with a freshly hashed code.
     *
     * Mint write path of the verification layer, symmetric with the identity
     * layer's {@see Identities::createPasswordIdentity()}: the plaintext code is
     * hashed here and the hash is written with a targeted UPDATE, so the code
     * hash is minted and stored entirely inside the layer and never reaches the
     * ORM columns, the object/view surface, or the cross-worker sync bus. The row
     * is first inserted through the ORM (which carries the non-secret columns and
     * assigns the id) and the hash is then set with a follow-up UPDATE.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id, or null when unknown at issue time
     * @param string $plainCode Plaintext code to hash and store
     * @param int $ttlSeconds Seconds until the challenge expires
     * @return ObjectUserVerification The created challenge object
     * @throws EmptyValueException When identifier or code is empty
     * @throws DatabaseException If the insert or code hash write query fails
     */
    public function createChallenge(
        string $type,
        string $identifier,
        ?int $userId,
        string $plainCode,
        int $ttlSeconds,
    ): ObjectUserVerification {
        if ($identifier === '' || $plainCode === '') {
            throw new EmptyValueException('Verification identifier and code are required');
        }

        $verification = ObjectUserVerification::create();
        $verification->userId = $userId;
        $verification->type = $type;
        $verification->identifier = $identifier;
        $verification->expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $verification->sync();

        $id = $verification->id;
        if ($id === null) {
            throw new DatabaseException('Verification insert did not assign an id');
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(password_hash($plainCode, PASSWORD_DEFAULT)));
        $params->add(SqlParam::int($id));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `' . EntityUserVerification::code_hash . '` = ? WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        );

        $this->objects[$id] = $verification;

        return $verification;
    }

    /**
     * Voids every active challenge for a (type, identifier) by consuming it.
     *
     * Called before issuing a fresh challenge so at most one is ever active,
     * which keeps {@see findActive()} unambiguous.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $maxAttempts Attempt ceiling excluding exhausted challenges
     * @throws DatabaseException If the database query fails
     */
    public function voidActive(string $type, string $identifier, int $maxAttempts): void
    {
        if ($type === '' || $identifier === '') {
            return;
        }

        $now = TimeHelper::getSqlDateTime();
        foreach ($this->hydrateByIdentity($type, $identifier) as $verification) {
            if ($verification->isActive($now, $maxAttempts)) {
                $verification->consume();
            }
        }
    }

    /**
     * Ages the newest active challenge for a (type, identifier) into the past.
     *
     * Test-only support for the `verification:test:expire` CLI (HIL-317): resolves
     * the single active challenge (attempts ignored, so an exhausted-but-unexpired
     * challenge still resets) and expires it via
     * {@see ObjectUserVerification::expire()}, so the next verify reads an expired
     * code. Returns the expired challenge, or null when none is active.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (matched verbatim)
     * @return ?ObjectUserVerification The expired challenge, or null when none active
     * @throws DatabaseException If the lookup or expiry query fails
     */
    public function expireActive(string $type, string $identifier): ?ObjectUserVerification
    {
        $challenge = $this->findActive($type, $identifier, PHP_INT_MAX);
        $challenge?->expire();

        return $challenge;
    }

    /**
     * Loads and caches every challenge row for a (type, identifier) pair.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<ObjectUserVerification> Challenge objects (empty when none)
     * @throws DatabaseException If the database query fails
     */
    private function hydrateByIdentity(string $type, string $identifier): array
    {
        $entities = EntityUserVerification::get([
            EntityUserVerification::type => $type,
            EntityUserVerification::identifier => $identifier,
        ]);

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->objects[$entity->id] = ObjectUserVerification::fromEntity($entity);
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }
}
