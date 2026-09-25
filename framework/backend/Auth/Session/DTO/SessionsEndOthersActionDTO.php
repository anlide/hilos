<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Empty request to end every other ordinary session of the acting user.
 */
final class SessionsEndOthersActionDTO extends ActionPayloadDTO
{
    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_SESSIONS_END_OTHERS;
    }

    /**
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Empty request
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /** @return array<string, mixed> Empty payload */
    public function toArray(): array
    {
        return [];
    }
}
