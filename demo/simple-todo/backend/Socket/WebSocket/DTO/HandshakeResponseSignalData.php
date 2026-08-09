<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Socket\WebSocket\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeResponseSignalData - Signal data for handshake response.
 *
 * Carries the session-scope payload in the `{entities: {currentUser: {...}}}`
 * wire form: the frontend normalizer upserts the current-user entity fragment
 * into the session entity store and places the current-user reference in the
 * session data store.
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
     * @param int $selfId Current authorized user id
     * @param string $selfName Current authorized user display name
     */
    public function __construct(
        public readonly int $selfId,
        public readonly string $selfName,
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
                self::currentUser => [
                    self::id => $this->selfId,
                    self::name => $this->selfName,
                ],
            ],
        ];
    }

    /**
     * Create DTO from wire payload.
     *
     * The payload is the one `toArray()` writes, so both keys of the current-user
     * fragment are always there. A payload without them names no user at all, and
     * an anonymous handshake is not the same thing as a user with no name.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no current-user fragment
     */
    public static function fromArray(array $data): static
    {
        $entities = $data[self::entities] ?? null;
        $currentUser = is_array($entities) ? ($entities[self::currentUser] ?? null) : null;
        if (!is_array($currentUser) || !isset($currentUser[self::id], $currentUser[self::name])) {
            throw new InvalidFormatException('Handshake response payload carries no current-user fragment.');
        }

        return new static(
            selfId: (int)$currentUser[self::id],
            selfName: (string)$currentUser[self::name],
        );
    }
}
