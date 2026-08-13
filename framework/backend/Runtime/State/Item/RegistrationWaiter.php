<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Registration\RegistrationReservationService;

/**
 * RegistrationWaiter - one session parked on the code step of a registration (HIL-415).
 *
 * Converge is a property of the whole sign-in surface, not a trick of registration:
 * a step can be rebuilt by an event nobody in that browser caused. This row is what
 * makes the registration case of it possible - it names the connections waiting on
 * an identifier's confirmation code, so when the code comes back from ANY of them
 * (or when the hold expires and nobody's did), every one of them can be moved:
 * to the done step signed in, or back to the identifier step.
 *
 * Keyed by accept key, not by identifier: the broadcast goes out per connection,
 * and a dropped connection must remove exactly one row. Several rows therefore
 * carry the same identifier, which is the normal state of a converged address -
 * two tabs, two devices, two people who both typed it.
 *
 * Transient by nature and by design. It is worth nothing after a restart (the
 * sockets are gone too), which is exactly why the reservation it waits on is
 * durable and this is not ({@see RegistrationReservationService}). The owning
 * agent's tick prunes rows whose connection is no longer live, so a browser closed
 * on the code screen leaves nothing behind.
 */
final class RegistrationWaiter extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosRegistrationWaiters';

    public const string acceptKey = 'acceptKey';
    public const string identifier = 'identifier';
    public const string sessionToken = 'sessionToken';

    /** Accept key of the waiting connection; also this row's id and the broadcast target. */
    private(set) string $acceptKey = '';

    /** Normalized identifier whose confirmation the connection is waiting for. */
    public string $identifier = '';

    /** Session token to sign in when somebody's code confirms the registration. */
    public string $sessionToken = '';

    /**
     * Parks a connection on the code step of an identifier.
     *
     * @param string $acceptKey Accept key of the waiting connection (row id)
     * @param string $identifier Normalized identifier being confirmed (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
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
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = (string)$row[self::acceptKey];
        $instance->identifier = (string)$row[self::identifier];
        $instance->sessionToken = (string)$row[self::sessionToken];
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for parked registration sessions
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * Re-points an already parked connection at another identifier.
     *
     * The base body is empty, so without this the in-place re-park writes nothing —
     * here and on every worker the sync replays it to. A connection that goes back
     * and submits a different address would keep waiting under the first one and be
     * signed into whatever account THAT one produces. The accept key is the row id
     * and never moves.
     *
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::identifier])) {
            $this->identifier = (string)$diff[self::identifier];
        }
        if (isset($diff[self::sessionToken])) {
            $this->sessionToken = (string)$diff[self::sessionToken];
        }
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
        ];
    }
}
