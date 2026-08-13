<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

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
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string entities = 'entities';
    public const string currentUser = 'currentUser';
    public const string impersonatedBy = 'impersonatedBy';
    public const string id = 'id';
    public const string name = 'name';
    public const string admin = 'admin';

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
     */
    public function __construct(
        public readonly ?int $selfId = null,
        public readonly ?string $selfName = null,
        public readonly bool $selfAdmin = false,
        public readonly ?int $impersonatorId = null,
        public readonly ?string $impersonatorName = null,
    ) {
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
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a present identity node carries no id or no admin flag
     */
    public static function fromArray(array $data): static
    {
        $entities = self::optionalArray($data, self::entities) ?? [];
        $currentUser = self::optionalArray($entities, self::currentUser);
        $impersonatedBy = self::optionalArray($entities, self::impersonatedBy);
        if ($currentUser === null) {
            return new static();
        }

        return new static(
            selfId: self::requireInt($currentUser, self::id),
            selfName: self::optionalString($currentUser, self::name),
            selfAdmin: self::requireBool($currentUser, self::admin),
            impersonatorId: $impersonatedBy === null ? null : self::requireInt($impersonatedBy, self::id),
            impersonatorName: $impersonatedBy === null ? null : self::optionalString($impersonatedBy, self::name),
        );
    }
}
