<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ProfilePasswordUpdatedSignalData - the profile set-password success signal (HIL-402, HIL-1137).
 *
 * A change rewrites only the (never-projected) secret, so nothing in the identity
 * projection moves to confirm it; success is therefore signalled explicitly. The
 * signal is delivered WS_USER to every one of the person's connections, so the
 * initiating tab clears its form and any other open tab can toast the change. The
 * {@see mode} distinguishes a first-time add from a change so the client can word
 * its confirmation.
 */
final class ProfilePasswordUpdatedSignalData extends BaseDTO implements SignalDataInterface
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
     * @throws InvalidFormatException When the payload names no mode
     */
    public static function fromArray(array $data): static
    {
        return new static(
            mode: self::requireString($data, self::MODE),
        );
    }
}
