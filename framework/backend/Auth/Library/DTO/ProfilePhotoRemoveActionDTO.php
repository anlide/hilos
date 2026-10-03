<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Remove the acting person's published photo; the client names no person. */
final class ProfilePhotoRemoveActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Owned action name */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_PHOTO_REMOVE;
    }

    /**
     * @param array<string, mixed> $data Ignored empty action payload
     * @return static Parsed action
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /** @return array<string, mixed> Empty transport payload */
    public function toArray(): array
    {
        return [];
    }
}
