<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Submit one complete cropped JPEG for the acting person's profile photo. */
final class ProfilePhotoSetActionDTO extends ActionPayloadDTO
{
    public const string clientUploadId = 'clientUploadId';

    public const array SECRET_FIELDS = [];

    /** @param string $clientUploadId Id of the completed upload */
    public function __construct(public readonly string $clientUploadId)
    {
    }

    /** @return string Owned action name */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_PHOTO_SET;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed action
     * @throws InvalidFormatException When the upload id is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::clientUploadId));
    }

    /** @return array{clientUploadId: string} Transport payload */
    public function toArray(): array
    {
        return [self::clientUploadId => $this->clientUploadId];
    }
}
