<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Opens a password change; the server resolves the account address (HIL-300). */
final class ProfileChangePasswordOpenActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
