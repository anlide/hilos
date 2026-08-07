<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeStubCopy;

/**
 * HandshakeWelcomeSignalData - Payload of the framework handshake welcome frame.
 *
 * Outbound-only: sent by WebSocketClient as the first frame of every
 * connection, directly behind the 101 upgrade response. Carries the daemon
 * build timestamp (HILOS_BUILD_TIMESTAMP) that the frontend compares on every
 * (re)connect to force a refresh when the deployed build changed, and the
 * protected-mode block the master reads off the runtime row so a connection
 * caught by a cluster freeze learns at handshake that it is locked out — and,
 * with the copy resolved through {@see ProtectedModeStubCopy}, learns it in
 * words, so the maintenance surface is painted before any subscription is
 * attempted and no empty shell flashes.
 */
class HandshakeWelcomeSignalData extends BaseDTO implements SignalDataInterface
{
    // Field name constants
    public const string BUILD = 'build';
    public const string PROTECTED_MODE = 'protectedMode';
    public const string PROTECTED_MODE_ACTIVE = 'active';
    public const string PROTECTED_MODE_OPERATION = 'operation';
    public const string PROTECTED_MODE_TITLE = 'title';
    public const string PROTECTED_MODE_MESSAGE = 'message';

    /**
     * @param string $build Daemon build timestamp ('dev' when not configured)
     * @param bool $protectedModeActive Whether this connection is locked out by an active protected-mode freeze
     * @param ?string $protectedModeOperation Operation the freeze protects; null when no freeze holds
     * @param ?string $protectedModeTitle Heading of the maintenance surface; null when no freeze holds
     * @param ?string $protectedModeMessage Sentence under the heading; null when no freeze holds
     */
    public function __construct(
        public readonly string $build,
        public readonly bool $protectedModeActive = false,
        public readonly ?string $protectedModeOperation = null,
        public readonly ?string $protectedModeTitle = null,
        public readonly ?string $protectedModeMessage = null,
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
                self::PROTECTED_MODE_OPERATION => $this->protectedModeOperation,
                self::PROTECTED_MODE_TITLE => $this->protectedModeTitle,
                self::PROTECTED_MODE_MESSAGE => $this->protectedModeMessage,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $protectedMode = $data[self::PROTECTED_MODE] ?? [];
        if (!is_array($protectedMode)) {
            $protectedMode = [];
        }

        return new static(
            build: (string)($data[self::BUILD] ?? ''),
            protectedModeActive: (bool)($protectedMode[self::PROTECTED_MODE_ACTIVE] ?? false),
            protectedModeOperation: self::text($protectedMode, self::PROTECTED_MODE_OPERATION),
            protectedModeTitle: self::text($protectedMode, self::PROTECTED_MODE_TITLE),
            protectedModeMessage: self::text($protectedMode, self::PROTECTED_MODE_MESSAGE),
        );
    }

    /**
     * Reads one optional string off the protected-mode block.
     *
     * @param array<string, mixed> $protectedMode Protected-mode block of the frame
     * @param string $field Field to read
     * @return ?string Field value, or null when the block does not carry it as text
     */
    private static function text(array $protectedMode, string $field): ?string
    {
        $value = $protectedMode[$field] ?? null;

        return is_string($value) ? $value : null;
    }
}
