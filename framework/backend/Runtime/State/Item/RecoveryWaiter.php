<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RecoveryWaiter - one session parked on the code step of a password recovery (HIL-416).
 *
 * The recovery case of the sign-in surface's converge property, and the sibling of
 * {@see RegistrationWaiter}: one address holds one reset code, so several sessions
 * can legitimately be waiting on it, and whoever saves a new password first settles
 * the address for all of them - the rest are moved back to the identifier step
 * rather than left typing a code that no longer buys anything.
 *
 * It carries one thing registration's waiter does not: {@see $codeAccepted}. Recovery
 * takes two steps where registration takes one, and the grant to reach the second is
 * exactly this flag - the row is parked when the code goes out and flipped when a
 * code comes back, so the password screen belongs to the session that proved the
 * address and to its tabs, and to nobody else. Keyed by accept key for the same
 * reason as registration's: the broadcast goes out per connection, and a dropped
 * connection must remove exactly one row.
 *
 * There is no expiry field. The grant lives exactly as long as the unspent code
 * behind it ({@see PasswordRecoveryService}), and a second clock that could disagree
 * with the first would only be a way to be wrong. Transient by nature: after a
 * restart the sockets are gone too, and the owning agent's tick prunes rows whose
 * connection is no longer live.
 */
final class RecoveryWaiter extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosRecoveryWaiters';

    public const string acceptKey = 'acceptKey';
    public const string identifier = 'identifier';
    public const string sessionToken = 'sessionToken';
    public const string codeAccepted = 'codeAccepted';

    /** Accept key of the waiting connection; also this row's id and the broadcast target. */
    private(set) string $acceptKey = '';

    /** Normalized identifier whose reset code the connection is waiting for. */
    public string $identifier = '';

    /** Session token the grant is bound to, so all tabs of one session share it. */
    public string $sessionToken = '';

    /** Whether this session has already proven the code and may save a new password. */
    public bool $codeAccepted = false;

    /**
     * Parks a connection on the code step of a recovery, without a grant yet.
     *
     * @param string $acceptKey Accept key of the waiting connection (row id)
     * @param string $identifier Normalized identifier being recovered (lowercased email)
     * @param string $sessionToken Session token the grant will be bound to
     * @return static Fresh waiter row
     */
    public static function create(string $acceptKey, string $identifier, string $sessionToken): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->identifier = $identifier;
        $instance->sessionToken = $sessionToken;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Waiter row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the waiter is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->identifier = self::requireString($row, self::identifier);
        $instance->sessionToken = self::requireString($row, self::sessionToken);
        $instance->codeAccepted = self::requireBool($row, self::codeAccepted);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for parked recovery sessions
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * Applies an in-place update: a re-park onto another address, or the grant.
     *
     * The base body is empty, so without this every in-place write would be lost -
     * here and on every worker the sync replays it to. That is the whole of the
     * grant: a row whose `codeAccepted` never arrives leaves the session unable to
     * save the password it just proved the code for. The accept key is the row id
     * and never moves.
     *
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->identifier = self::patchString($diff, self::identifier, $this->identifier);
        $this->sessionToken = self::patchString($diff, self::sessionToken, $this->sessionToken);
        $this->codeAccepted = self::patchBool($diff, self::codeAccepted, $this->codeAccepted);
    }

    /**
     * @return string Runtime row id, the waiting connection's accept key
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::identifier => $this->identifier,
            self::sessionToken => $this->sessionToken,
            self::codeAccepted => $this->codeAccepted,
        ];
    }
}
