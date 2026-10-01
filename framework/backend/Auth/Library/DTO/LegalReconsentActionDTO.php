<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A signed-in person's read of the documents that wait for their decision (HIL-500). */
final class LegalReconsentActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Re-consent content action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LEGAL_RECONSENT;
    }

    /**
     * @param array<string, mixed> $data Empty action payload
     * @return static Stateless re-consent request
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
