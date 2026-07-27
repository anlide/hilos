<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeWelcomeSignalData - Payload of the framework handshake welcome frame.
 *
 * Outbound-only: sent by WebSocketClient as the first frame of every
 * connection, directly behind the 101 upgrade response. Carries the daemon
 * build timestamp (HILOS_BUILD_TIMESTAMP) that the frontend compares on every
 * (re)connect to force a refresh when the deployed build changed, and the
 * protected-mode flag the master reads off the runtime row so a connection
 * caught by a cluster freeze learns at handshake that it is locked out.
 */
class HandshakeWelcomeSignalData extends BaseDTO implements SignalDataInterface
{
    // Field name constants
    public const string BUILD = 'build';
    public const string PROTECTED_MODE = 'protectedMode';
    public const string PROTECTED_MODE_ACTIVE = 'active';

    /**
     * @param string $build Daemon build timestamp ('dev' when not configured)
     * @param bool $protectedModeActive Whether this connection is locked out by an active protected-mode freeze
     */
    public function __construct(
        public readonly string $build,
        public readonly bool $protectedModeActive = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::BUILD => $this->build,
            self::PROTECTED_MODE => [
                self::PROTECTED_MODE_ACTIVE => $this->protectedModeActive,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $protectedMode = $data[self::PROTECTED_MODE] ?? [];

        return new static(
            build: (string)($data[self::BUILD] ?? ''),
            protectedModeActive: is_array($protectedMode)
                && (bool)($protectedMode[self::PROTECTED_MODE_ACTIVE] ?? false),
        );
    }
}
