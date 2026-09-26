<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * AccountDeletionCancelActionDTO - DTO for calling off a scheduled account deletion (HIL-302).
 *
 * Carries no fields and needs no code: calling it off only keeps the account where it is.
 */
final class AccountDeletionCancelActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL;
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
