<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Runtime\State\Collection\HilosSessionRotations;

/**
 * HilosSessionRotation - one pending token rotation, keyed by its one-time ticket (HIL-582).
 *
 * The row that lets a login rotate the session token without a window an attacker can
 * poll. The seam mints a ticket, writes this row, and sends the ticket to the initiating
 * connection alone; the master trades it for the new token on that connection's next 101
 * and drops the row. Everyone else - including whoever knew the pre-login token - gets a
 * fresh anonymous session instead.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosSessionRotations}).
 * It is written in a worker by the agent that owns the session seam and read in the master
 * on the handshake, which is exactly why it is runtime state rather than a field somewhere:
 * RT sync is what carries it across that process boundary.
 *
 * Rows are written once and never changed - a rotation is what it is - so there is no diff
 * to apply beyond the one an inbound sync brings, and they leave the collection in one of
 * two ways: burned by the exchange, or swept once their moment has passed.
 *
 * The row also carries the success ack the initiating connection still owed when the
 * rotation was announced (HIL-423). An ack lives on the CONNECTION (HIL-422), and the
 * rotation kills the connection that earned it: the browser comes back on a socket that
 * owes nothing, and the sentence the person just earned is gone before it was read. The
 * ticket is what mends that, and only the ticket - a socket that presents one is the same
 * browser continuing the flow it started, whereas a bare reopened socket is a reload and
 * still owes nothing.
 */
final class HilosSessionRotation extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosSessionRotations';

    public const string ticket = 'ticket';
    public const string sessionToken = 'sessionToken';
    public const string acceptKeysToDrop = 'acceptKeysToDrop';
    public const string expiresAtMs = 'expiresAtMs';
    public const string pendingAck = 'pendingAck';

    /** One-time ticket naming this rotation; also the row id. */
    private(set) string $ticket = '';

    /** Session token the ticket's bearer receives on the next handshake. */
    private(set) string $sessionToken = '';

    /**
     * @var list<string> Accept keys of the session's other connections, dropped after the exchange
     */
    private(set) array $acceptKeysToDrop = [];

    /** Unix milliseconds after which the ticket is no longer honoured. */
    private(set) float $expiresAtMs = 0.0;

    /** Ack the initiating connection still owed when the rotation was announced, or null. */
    private(set) ?string $pendingAck = null;

    /**
     * Builds a pending rotation row.
     *
     * @param string $ticket One-time ticket naming the rotation
     * @param string $sessionToken Session token the bearer receives
     * @param list<string> $acceptKeysToDrop Accept keys dropped once the exchange happens
     * @param float $expiresAtMs Unix milliseconds after which the ticket stops being honoured
     * @param ?string $pendingAck Ack the initiating connection still owed (a SessionAck value), or null
     * @return static Fresh rotation row
     */
    public static function create(
        string $ticket,
        string $sessionToken,
        array $acceptKeysToDrop,
        float $expiresAtMs,
        ?string $pendingAck = null,
    ): static {
        $instance = new static();
        $instance->ticket = $ticket;
        $instance->sessionToken = $sessionToken;
        $instance->acceptKeysToDrop = $acceptKeysToDrop;
        $instance->expiresAtMs = $expiresAtMs;
        $instance->pendingAck = $pendingAck;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->ticket = (string)$row[self::ticket];
        $instance->sessionToken = (string)$row[self::sessionToken];
        $instance->acceptKeysToDrop = self::stringList($row[self::acceptKeysToDrop] ?? []);
        $instance->expiresAtMs = (float)($row[self::expiresAtMs] ?? 0.0);
        $instance->pendingAck = self::stringOrNull($row[self::pendingAck] ?? null);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * A rotation is never edited after it is written, so this only ever runs for a worker
     * that hydrated the row before the writer finished it. Every field is still accepted:
     * a diff arriving with a field this ignored would be a row silently disagreeing across
     * processes, and the one it would disagree about is the token.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::sessionToken, $diff)) {
            $this->sessionToken = (string)$diff[self::sessionToken];
        }
        if (array_key_exists(self::acceptKeysToDrop, $diff)) {
            $this->acceptKeysToDrop = self::stringList($diff[self::acceptKeysToDrop]);
        }
        if (array_key_exists(self::expiresAtMs, $diff)) {
            $this->expiresAtMs = (float)$diff[self::expiresAtMs];
        }
        if (array_key_exists(self::pendingAck, $diff)) {
            $this->pendingAck = self::stringOrNull($diff[self::pendingAck]);
        }
    }

    /**
     * Tells whether the ticket is still honoured at a given instant.
     *
     * @param float $nowMs Unix milliseconds to judge against
     * @return bool True while the rotation is still live
     */
    public function isLiveAt(float $nowMs): bool
    {
        return $nowMs < $this->expiresAtMs;
    }

    /**
     * @return string Runtime collection key for rotation rows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the one-time ticket
     */
    public function getId(): string
    {
        return $this->ticket;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::ticket => $this->ticket,
            self::sessionToken => $this->sessionToken,
            self::acceptKeysToDrop => $this->acceptKeysToDrop,
            self::expiresAtMs => $this->expiresAtMs,
            self::pendingAck => $this->pendingAck,
        ];
    }

    /**
     * @param mixed $value Raw row value
     * @return ?string Value as a string, or null when the row carries none
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    /**
     * @param mixed $value Raw row value
     * @return list<string> Accept keys as a plain string list
     */
    private static function stringList(mixed $value): array
    {
        $keys = [];
        foreach ((array)$value as $key) {
            if (is_scalar($key)) {
                $keys[] = (string)$key;
            }
        }

        return $keys;
    }
}
