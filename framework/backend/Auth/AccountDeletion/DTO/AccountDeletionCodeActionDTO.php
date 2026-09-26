<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * AccountDeletionCodeActionDTO - DTO for sending the code that confirms an account deletion (HIL-302).
 *
 * Carries no fields: the address is read from the account again, never from the client.
 */
final class AccountDeletionCodeActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE;
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
