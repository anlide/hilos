<?php

declare(strict_types=1);

namespace Demo\Chat\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeResponseSignalData - Signal data for handshake response.
 *
 * Carries the session-scope payload in the `{entities: {currentUser: {...}}}`
 * wire form: the frontend normalizer upserts the current-user entity fragment
 * into the session entity store and places the current-user reference in the
 * session data store. Display name updates, chat snapshot, and session fields
 * are sent through browser rows after page subscription.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string entities = 'entities';
    public const string currentUser = 'currentUser';
    public const string id = 'id';
    public const string name = 'name';

    /**
     * Creates handshake response signal data.
     *
     * Both fields are null for an anonymous session, which clears the frontend
     * current user; an authenticated session carries the durable user id and name.
     *
     * @param ?int $selfId Authenticated user id, or null when the session is anonymous
     * @param ?string $selfName Authenticated user display name, or null when anonymous
     */
    public function __construct(
        public readonly ?int $selfId = null,
        public readonly ?string $selfName = null,
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
        $currentUser = is_array($entities) ? ($entities[self::currentUser] ?? null) : null;
        if (!is_array($currentUser)) {
            return new static();
        }

        return new static(
            selfId: (int)($currentUser[self::id] ?? 0),
            selfName: (string)($currentUser[self::name] ?? ''),
        );
    }
}
