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
 * (re)connect to force a refresh when the deployed build changed.
 */
class HandshakeWelcomeSignalData extends BaseDTO implements SignalDataInterface
{
    // Field name constants
    public const string BUILD = 'build';

    /**
     * @param string $build Daemon build timestamp ('dev' when not configured)
     */
    public function __construct(
        public readonly string $build,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            self::BUILD => $this->build,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            build: (string)($data[self::BUILD] ?? ''),
        );
    }
}
