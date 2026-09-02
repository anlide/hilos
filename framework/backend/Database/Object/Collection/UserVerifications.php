<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\Verification\VerificationRejectReason;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\UserVerifications as EntityUserVerifications;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationSendStats;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserVerifications object collection.
 *
 * Persistence primitives of the verification layer (HIL-365): mint a challenge,
 * find the single active challenge for a (type, identifier), void prior active
 * challenges, and read how often that pair has been mailed (HIL-421). The
 * orchestration (throttle, delivery, attempt handling) lives in
 * {@see VerificationService}; the code hash is minted here with a targeted query
 * so it never reaches the ORM columns.
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * Names why a (type, identifier) pair has no active challenge.
     *
     * The companion of {@see findActive()}, for the caller that just got null from it
     * and has to write down what happened (HIL-607). It asks no second question of the
     * database: {@see hydrateByIdentity()} has already loaded every row for the pair
     * and cached it, so this reads the same objects the failed lookup walked.
     *
     * Only INACTIVE rows are considered, and the newest of them answers — it is the
     * challenge the person most likely just tried to spend. The order of the checks is
     * from most specific to least, because the states overlap: a challenge that ran out
     * of attempts is consumed by the service on the way out, so asking about
     * consumption first would hide exhaustion forever. When no row explains the
     * refusal — nothing was ever issued, or every row is somehow still active — the
     * answer is {@see VerificationRejectReason::NO_CHALLENGE}, which is what an
     * operator reading the log needs to be told: nothing here accounts for it.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $maxAttempts Attempt ceiling the caller judged activity by
     * @return string Why there is no active challenge (see VerificationRejectReason)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function describeInactive(string $type, string $identifier, int $maxAttempts): string
    {
        if ($type === '' || $identifier === '') {
            return VerificationRejectReason::NO_CHALLENGE;
        }

        $now = TimeHelper::getSqlDateTime();
        $newest = null;
        foreach ($this->hydrateByIdentity($type, $identifier) as $verification) {
            if ($verification->isActive($now, $maxAttempts)) {
                continue;
            }
            if ($newest === null || (int)$verification->id > (int)$newest->id) {
                $newest = $verification;
            }
        }

        if ($newest === null) {
            return VerificationRejectReason::NO_CHALLENGE;
        }
        if ($newest->attempts >= $maxAttempts) {
            return VerificationRejectReason::ATTEMPTS_EXHAUSTED;
        }
        if ($newest->consumedAt !== null) {
            return VerificationRejectReason::CONSUMED;
        }
        if ($newest->expiresAt <= $now) {
            return VerificationRejectReason::EXPIRED;
        }

        return VerificationRejectReason::NO_CHALLENGE;
    }

    /**
     * Reads what the challenge rows say about sends to one (type, identifier).
     *
     * The single query behind the send gate (HIL-421): the newest issue time and
     * the number of issues inside the window, taken in one pass over the same rows
     * every other primitive here loads. Both are read off `created_at` and no row
     * is filtered by state, because a challenge that was voided, consumed or
     * expired was still delivered to the target - what limits a mailbox is the
     * issue, not the code's survival.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param int $windowSeconds Length of the window sends are counted in
     * @return VerificationSendStats Newest issue time and issues inside the window
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function sendStats(string $type, string $identifier, int $windowSeconds): VerificationSendStats
    {
        if ($type === '' || $identifier === '') {
            return VerificationSendStats::never();
        }

        $windowStart = time() - $windowSeconds;
        $lastIssuedAt = null;
        $sentInWindow = 0;
        foreach ($this->hydrateByIdentity($type, $identifier) as $verification) {
            $issuedAt = strtotime($verification->createdAt);
            if ($issuedAt === false) {
                continue;
            }
            if ($lastIssuedAt === null || $issuedAt > $lastIssuedAt) {
                $lastIssuedAt = $issuedAt;
            }
            if ($issuedAt > $windowStart) {
                $sentInWindow++;
            }
        }

        if ($lastIssuedAt === null) {
            return VerificationSendStats::never();
        }

        return VerificationSendStats::issued($lastIssuedAt, $sentInWindow);
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
     * The issue time is stamped here rather than left to the column default: it is
     * an ORM-mapped column, so the insert carries a value for it either way, and
     * this is the one place that knows a challenge is being issued. It is what
     * {@see sendStats()} counts, and expiry is derived from the same instant.
     *
     * The channel defaults to null, which is what every flow that never offered a
     * choice mints (HIL-492): null reads as "delivered by the type's own rule", so
     * an email confirmation and a profile add-phone code carry no channel and are
     * unaffected. Only the phone-login path, where the person picked a channel,
     * names one - and it is written at the mint because that is the only moment the
     * choice exists; nothing later may rewrite which channel a code went out over.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $identifier Normalized identifier (lowercased email)
     * @param ?int $userId Owning user id, or null when unknown at issue time
     * @param string $plainCode Plaintext code to hash and store
     * @param int $ttlSeconds Seconds until the challenge expires
     * @param ?string $channel Code channel the challenge is delivered over, or null for the type's own rule
     * @return ObjectUserVerification The created challenge object
     * @throws EmptyValueException When identifier or code is empty
     * @throws DatabaseException If the insert or code hash write query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function createChallenge(
        string $type,
        string $identifier,
        ?int $userId,
        string $plainCode,
        int $ttlSeconds,
        ?string $channel = null,
    ): ObjectUserVerification {
        if ($identifier === '' || $plainCode === '') {
            throw new EmptyValueException('Verification identifier and code are required');
        }

        $now = time();
        $verification = ObjectUserVerification::create();
        $verification->userId = $userId;
        $verification->type = $type;
        $verification->identifier = $identifier;
        $verification->channel = $channel;
        $verification->createdAt = date('Y-m-d H:i:s', $now);
        $verification->expiresAt = date('Y-m-d H:i:s', $now + $ttlSeconds);
        $verification->sync();

        $id = $verification->id;
        if ($id === null) {
            throw new DatabaseException('Verification insert did not assign an id');
        }

        DbWriteGuard::guardItemWrite(static::COLLECTION_KEY, (string)$id, TruthSourceOperation::Update);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(password_hash($plainCode, PASSWORD_DEFAULT)));
        $params->add(SqlParam::int($id));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `' . EntityUserVerification::code_hash
                . '` = ? WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        );

        $this[$id] = $verification;

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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
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
                $this->hydrate($entity->id, ObjectUserVerification::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }
}
