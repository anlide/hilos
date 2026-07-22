<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
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

    /**
     * Creates handshake response signal data.
     *
     * The current-user fields are null for an anonymous session, which clears the
     * frontend current user; an authenticated session carries the durable user id
     * and name. The impersonator fields are null unless the session is being
     * impersonated, in which case they carry the admin behind the impersonation.
     *
     * @param ?int $selfId Authenticated user id, or null when the session is anonymous
     * @param ?string $selfName Authenticated user display name, or null when anonymous
     * @param ?int $impersonatorId Impersonating admin's user id, or null when not impersonating
     * @param ?string $impersonatorName Impersonating admin's display name, or null when not impersonating
     */
    public function __construct(
        public readonly ?int $selfId = null,
        public readonly ?string $selfName = null,
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
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $entities = $data[self::entities] ?? null;
        $entities = is_array($entities) ? $entities : [];
        $currentUser = $entities[self::currentUser] ?? null;
        $impersonatedBy = $entities[self::impersonatedBy] ?? null;
        if (!is_array($currentUser)) {
            return new static();
        }

        return new static(
            selfId: (int)($currentUser[self::id] ?? 0),
            selfName: (string)($currentUser[self::name] ?? ''),
            impersonatorId: is_array($impersonatedBy) ? (int)($impersonatedBy[self::id] ?? 0) : null,
            impersonatorName: is_array($impersonatedBy) ? (string)($impersonatedBy[self::name] ?? '') : null,
        );
    }
}
