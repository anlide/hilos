<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_oauth_redirect_reset action payload (HIL-286).
 *
 * Carries nothing: there is one return address, and resetting it drops the admin
 * setting so the address falls back to env.
 */
final class HilosOAuthRedirectResetActionDTO extends ActionPayloadDTO
{
    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_OAUTH_REDIRECT_RESET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (carries nothing this action reads)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * @return array<string, mixed> Empty data
     */
    public function toArray(): array
    {
        return [];
    }
}
