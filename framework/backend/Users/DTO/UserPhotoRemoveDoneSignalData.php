<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The person's agent → users library: the profile photo is removed, or why not (HIL-1409).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE} carries. The ask comes back whole,
 * and the file id of the photo that was removed with it, so {@see AbstractUsersLibraryAgent} can
 * release that file from the frame alone. No file means the person had no photo. The refusal is the
 * three fields of {@see ActionRefusal}, and it names no file.
 */
final class UserPhotoRemoveDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string fileId = 'fileId';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserPhotoRemoveSignalData $ask The ask being answered, untouched
     * @param ?int $fileId Registry file of the photo that was removed, or null when there was none
     * @param ?string $error Why the photo was not removed, or null when it was looked at
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserPhotoRemoveSignalData $ask,
        public readonly ?int $fileId,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserPhotoRemoveSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why the photo was not removed
     * @return self Answer carrying the ask back, with no file
     */
    public static function refused(UserPhotoRemoveSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Photo removal answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserPhotoRemoveSignalData::fromArray(self::requireArray($data, self::ask)),
            fileId: self::optionalInt($data, self::fileId),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::ask => $this->ask->toArray(),
            self::fileId => $this->fileId,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
