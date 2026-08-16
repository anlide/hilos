<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\Auth\Session\SessionAck;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeResponseSignalData - Signal data for the session handshake response.
 *
 * Framework-owned (HIL-361): the payload is entirely session-generic, so every
 * project reuses it and resolves the display names through its own
 * `handshakeResponseFor(session)` hook. Carries the session-scope payload in the
 * `{entities: {currentUser: {...}, impersonatedBy: null|{...}}}` wire form: the
 * frontend normalizer upserts the current-user (and, when impersonating, the
 * impersonating admin) entity fragment into the session entity store and places
 * the references in the session data store. The `impersonatedBy` slot is the
 * single source the frontend derives `impersonating` from (non-null ⇒ the
 * session is being impersonated), symmetric with `currentUser`: null clears it.
 * Display name updates, page snapshots, and session fields are sent through
 * browser rows after page subscription.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 *
 * Alongside the entities travels the plain `{data: {pendingAck: ...}}` section
 * (HIL-422): the ack the receiving CONNECTION still owes its person, or null when it
 * owes none. The key is written on every response rather than only when set, because
 * the frontend derives the surface from it — an omitted key would read as "unchanged"
 * where the clearing is the whole message. It is per-connection, so one broadcast of
 * the same identity carries a different value to each socket of the session.
 *
 * The same section carries the session context every socket is told (HIL-486):
 * `serverTimeMs`, the server's own "now", and `pendingRegistration`, the registration
 * this session started and has not finished. Server time rides the handshake because
 * the browser clock is not evidence — a countdown drawn against it runs out early or
 * never — so the client measures the offset once per handshake and reads every
 * absolute moment the backend sends against that. Both keys are written for an
 * anonymous session too: an anonymous session is exactly the one that may be halfway
 * through a registration, and it needs the clock to draw that step's expiry.
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string entities = 'entities';
    public const string currentUser = 'currentUser';
    public const string impersonatedBy = 'impersonatedBy';
    public const string id = 'id';
    public const string name = 'name';
    public const string admin = 'admin';
    public const string data = 'data';
    public const string pendingAck = 'pendingAck';
    public const string serverTimeMs = 'serverTimeMs';
    public const string pendingRegistration = 'pendingRegistration';
    public const string identifier = 'identifier';
    public const string kind = 'kind';
    public const string channel = 'channel';
    public const string expiresAt = 'expiresAt';

    /**
     * Creates handshake response signal data.
     *
     * The current-user fields are null for an anonymous session, which clears the
     * frontend current user; an authenticated session carries the durable user id
     * and name. The impersonator fields are null unless the session is being
     * impersonated, in which case they carry the admin behind the impersonation.
     *
     * The admin flag travels with the identity because the shell decides what to
     * show from it — the admin entry is drawn for an admin and for nobody else.
     * It is false for an anonymous session and for a project that answers no
     * admin identity, so a shell that was told nothing shows no admin entry:
     * the same fail-closed default the page access gate takes.
     *
     * @param ?int $selfId Authenticated user id, or null when the session is anonymous
     * @param ?string $selfName Authenticated user display name, or null when anonymous
     * @param bool $selfAdmin Whether the authenticated user holds the admin privilege
     * @param ?int $impersonatorId Impersonating admin's user id, or null when not impersonating
     * @param ?string $impersonatorName Impersonating admin's display name, or null when not impersonating
     * @param ?string $pendingAck Ack the receiving connection still owes (a {@see SessionAck} value), or null
     * @param ?int $serverTimeMs Server "now" in epoch milliseconds, or null before the session context is stamped
     * @param ?array{identifier: string, kind: string, channel: ?string, expiresAt: int} $pendingRegistration
     *     Registration the session left unfinished, or null when it has none
     */
    public function __construct(
        public readonly ?int $selfId = null,
        public readonly ?string $selfName = null,
        public readonly bool $selfAdmin = false,
        public readonly ?int $impersonatorId = null,
        public readonly ?string $impersonatorName = null,
        public readonly ?string $pendingAck = null,
        public readonly ?int $serverTimeMs = null,
        public readonly ?array $pendingRegistration = null,
    ) {
    }

    /**
     * Returns the same identity addressed to a connection that owes a different ack.
     *
     * The identity half of the response is built once per session — one user, one
     * name, one impersonator — while the ack half belongs to a single socket. A
     * broadcast therefore builds the response once and re-addresses it per connection
     * through this, instead of resolving the user again for every tab.
     *
     * @param ?string $pendingAck Ack the addressed connection owes (a {@see SessionAck} value), or null for none
     * @return self The same response carrying that ack
     */
    public function withPendingAck(?string $pendingAck): self
    {
        return new self(
            selfId: $this->selfId,
            selfName: $this->selfName,
            selfAdmin: $this->selfAdmin,
            impersonatorId: $this->impersonatorId,
            impersonatorName: $this->impersonatorName,
            pendingAck: $pendingAck,
            serverTimeMs: $this->serverTimeMs,
            pendingRegistration: $this->pendingRegistration,
        );
    }

    /**
     * Returns the same response stamped with the session context (HIL-486).
     *
     * The twin of {@see withPendingAck()} on the other axis: the identity half is
     * built by the project hook, which knows the user store and nothing about the
     * clock or the registration table, so the framework stamps those onto whatever
     * the project answered. Every send path goes through it — a response that left
     * either key out would silently keep the browser on the offset and the step it
     * happened to hold, which is exactly what a re-handshake exists to refresh.
     *
     * @param int $serverTimeMs Server "now" in epoch milliseconds
     * @param ?array{identifier: string, kind: string, channel: ?string, expiresAt: int} $pendingRegistration
     *     Registration the session left unfinished, or null when it has none
     * @return self The same response carrying that session context
     */
    public function withSessionContext(int $serverTimeMs, ?array $pendingRegistration): self
    {
        return new self(
            selfId: $this->selfId,
            selfName: $this->selfName,
            selfAdmin: $this->selfAdmin,
            impersonatorId: $this->impersonatorId,
            impersonatorName: $this->impersonatorName,
            pendingAck: $this->pendingAck,
            serverTimeMs: $serverTimeMs,
            pendingRegistration: $pendingRegistration,
        );
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::entities => [
                self::currentUser => $this->selfId === null
                    ? null
                    : [
                        self::id => $this->selfId,
                        self::name => $this->selfName,
                        self::admin => $this->selfAdmin,
                    ],
                self::impersonatedBy => $this->impersonatorId === null
                    ? null
                    : [
                        self::id => $this->impersonatorId,
                        self::name => $this->impersonatorName,
                    ],
            ],
            self::data => [
                self::pendingAck => $this->pendingAck,
                self::serverTimeMs => $this->serverTimeMs,
                self::pendingRegistration => $this->pendingRegistration,
            ],
        ];
    }

    /**
     * Create DTO from wire payload.
     *
     * An anonymous session is written as a null current-user node, so a payload
     * without one still builds the empty response a guest gets - that absence is
     * the contract, not a gap. What a present node may not do is arrive without
     * the fields that make it an identity: the id and the admin flag are required
     * inside it, and the display name is the one field a project is allowed to
     * answer as null. The impersonator node is read the same way when it is there.
     *
     * The ack is read on both branches: a response can clear the current user and
     * still owe the socket a sentence, and dropping it on the anonymous branch would
     * make the round trip lossy for exactly the payload the logout path sends. The
     * session context is read on both for the stronger reason: the session halfway
     * through a registration is anonymous by definition, so the anonymous branch is
     * the one that carries it.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a present identity or registration node lacks a required field
     */
    public static function fromArray(array $data): static
    {
        $entities = self::optionalArray($data, self::entities) ?? [];
        $currentUser = self::optionalArray($entities, self::currentUser);
        $impersonatedBy = self::optionalArray($entities, self::impersonatedBy);
        $section = self::optionalArray($data, self::data) ?? [];
        $pendingAck = self::optionalString($section, self::pendingAck);
        $serverTimeMs = self::optionalInt($section, self::serverTimeMs);
        $pendingRegistration = self::readPendingRegistration($section);
        if ($currentUser === null) {
            return new static(
                pendingAck: $pendingAck,
                serverTimeMs: $serverTimeMs,
                pendingRegistration: $pendingRegistration,
            );
        }

        return new static(
            selfId: self::requireInt($currentUser, self::id),
            selfName: self::optionalString($currentUser, self::name),
            selfAdmin: self::requireBool($currentUser, self::admin),
            impersonatorId: $impersonatedBy === null ? null : self::requireInt($impersonatedBy, self::id),
            impersonatorName: $impersonatedBy === null ? null : self::optionalString($impersonatedBy, self::name),
            pendingAck: $pendingAck,
            serverTimeMs: $serverTimeMs,
            pendingRegistration: $pendingRegistration,
        );
    }

    /**
     * Reads the unfinished-registration node back into its declared shape.
     *
     * Rebuilt field by field rather than handed through as the map that arrived:
     * this is the parse boundary, and the node is the one part of the response the
     * surface navigates by — a member missing from it would surface as a code screen
     * with no identifier to name and no moment to count down to.
     *
     * @param array<string, mixed> $section Plain data section of the response
     * @return ?array{identifier: string, kind: string, channel: ?string, expiresAt: int} Node, or null when absent
     * @throws InvalidFormatException When a present node lacks its identifier, kind, or expiry
     */
    private static function readPendingRegistration(array $section): ?array
    {
        $node = self::optionalArray($section, self::pendingRegistration);
        if ($node === null) {
            return null;
        }

        return [
            self::identifier => self::requireString($node, self::identifier),
            self::kind => self::requireString($node, self::kind),
            self::channel => self::optionalString($node, self::channel),
            self::expiresAt => self::requireInt($node, self::expiresAt),
        ];
    }
}
