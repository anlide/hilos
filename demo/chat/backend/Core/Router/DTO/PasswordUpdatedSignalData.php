<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PasswordUpdatedSignalData - the profile set-password success signal (HIL-402).
 *
 * A change rewrites only the (never-projected) secret, so nothing in the identity
 * projection moves to confirm it; success is therefore signalled explicitly. The
 * signal is delivered WS_USER to every one of the user's connections, so the
 * initiating tab clears its form and any other open tab can toast the change. The
 * {@see mode} distinguishes a first-time add from a change so the client can word
 * its confirmation.
 */
final class PasswordUpdatedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string MODE = 'mode';
    public const string MODE_ADDED = 'added';
    public const string MODE_CHANGED = 'changed';

    /**
     * @param string $mode Whether the password was added or changed (see MODE_* constants)
     */
    public function __construct(
        public readonly string $mode,
    ) {
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::MODE => $this->mode,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            mode: (string)($data[self::MODE] ?? self::MODE_CHANGED),
        );
    }
}
