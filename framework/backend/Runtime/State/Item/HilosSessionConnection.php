<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * Inheritable runtime row for one WebSocket connection — the session stage (HIL-509).
 *
 * The stage above {@see HilosConnection}: it adds the `sessionToken` the socket
 * belongs to, which is what turns a set of live sockets into the live sockets of a
 * browser session. A project stands here once it carries sessions at all; a
 * project that does not stays on the presence stage, and the session seams are
 * then missing from its type rather than answering empty.
 *
 * The token moves in exactly one case, and the distinction is the whole of the rule
 * (HIL-582). A socket that changes SESSION is still a new row, as it always was —
 * nothing re-points a connection from one browser session to another. But a session
 * that is RENAMED keeps its sockets: the login rotation mints a new token for the
 * same session, and the initiating connection follows it through
 * {@see HilosSessionConnectionsActions::repointSessionToken()}. The row's identity is
 * the accept key, and that is what stays immutable.
 *
 * The stage also carries the pending success ack (HIL-422) — the mark a finished
 * auth flow leaves on the sockets of its session, so that the surface knows it has
 * something left to say before it closes. It lives on the CONNECTION rather than on
 * the session row on purpose: a reload kills the socket and the mark dies with it,
 * which is the whole of the "ephemeral" requirement, and nothing has to expire it.
 * It is seeded by no creation path — a socket opens with nothing to announce — and
 * only {@see HilosSessionConnectionsActions::markAck()} ever moves it.
 *
 * The base half of the row is composed by chaining {@see initBase()} /
 * {@see hydrateBase()} / {@see baseToArray()} / {@see applyBaseDiff()} through
 * `parent::` — the chain lives entirely inside the framework, so the "cannot be
 * skipped" contract the project sees ({@see HilosConnection::initOwn()} and its
 * three siblings) is unchanged. {@see create()} is final here: this is the last
 * stage, so nothing below it has a signature left to widen.
 */
abstract class HilosSessionConnection extends HilosConnection
{
    public const string sessionToken = 'sessionToken';
    public const string pendingAck = 'pendingAck';

    /** Session cookie token this connection belongs to, or null when it belongs to none. */
    private(set) ?string $sessionToken = null;

    /** Success ack this socket has yet to show, or null when it owes none. */
    private(set) ?string $pendingAck = null;

    /**
     * Creates a connection row for a freshly opened socket of a session.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous session
     * @param ?string $sessionToken Session cookie token this connection belongs to, or null when it belongs to none
     * @return static Connection row ready for the collection
     */
    final public static function create(string $acceptKey, ?int $userId, ?string $sessionToken = null): static
    {
        $instance = new static();
        $instance->initBase($acceptKey, $userId, $sessionToken);
        $instance->initOwn();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Seeds the base fields of both stages on a freshly created connection.
     *
     * The session token is optional in the signature because widening an
     * inherited one with a required parameter is not a legal override.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous session
     * @param ?string $sessionToken Session cookie token this connection belongs to, or null when it belongs to none
     */
    protected function initBase(string $acceptKey, ?int $userId, ?string $sessionToken = null): void
    {
        parent::initBase($acceptKey, $userId);
        $this->sessionToken = $sessionToken;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @throws InvalidFormatException When the row lost a base field or carries it as another type
     */
    protected function hydrateBase(array $row): void
    {
        parent::hydrateBase($row);
        $this->sessionToken = self::optionalString($row, self::sessionToken);
        $this->pendingAck = self::optionalString($row, self::pendingAck);
    }

    /**
     * @return array<string, mixed> Base fields of both stages (acceptKey, userId, sessionToken, pendingAck)
     */
    protected function baseToArray(): array
    {
        return array_merge(parent::baseToArray(), [
            self::sessionToken => $this->sessionToken,
            self::pendingAck => $this->pendingAck,
        ]);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When the diff carries a base field as another type
     */
    protected function applyBaseDiff(array $diff): void
    {
        parent::applyBaseDiff($diff);
        $this->sessionToken = self::patchOptionalString($diff, self::sessionToken, $this->sessionToken);
        $this->pendingAck = self::patchOptionalString($diff, self::pendingAck, $this->pendingAck);
    }
}
