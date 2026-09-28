<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Public read of registration's consent form and current documents. */
final class LegalConsentActionDTO extends ActionPayloadDTO
{
    /** @return string Public consent action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LEGAL_CONSENT;
    }

    /**
     * @param array<string, mixed> $data Empty action payload
     * @return static Stateless consent request
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /** @return array<string, mixed> Empty request payload */
    public function toArray(): array
    {
        return [];
    }
}
