<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * AccountDeletionOpenActionDTO - DTO for opening the account deletion window (HIL-302).
 *
 * Carries no fields: what the window shows - the grace period and where the code goes - is
 * read from the account and the settings, never from the client.
 */
final class AccountDeletionOpenActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN;
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
