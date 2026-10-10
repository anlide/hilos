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
 * The person's agent → users library: the profile photo is set, or why not (HIL-1409).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE} carries. The ask comes back whole,
 * and the former file id with it, so {@see AbstractUsersLibraryAgent} can release that file from
 * the frame alone. No former file means the person had no photo. The refusal is the three fields
 * of {@see ActionRefusal}, and it names no file.
 */
final class UserPhotoPutDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string formerFileId = 'formerFileId';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserPhotoPutSignalData $ask The ask being answered, untouched
     * @param ?int $formerFileId Registry file of the photo this one replaced, or null when there was none
     * @param ?string $error Why the photo was not set, or null when it was
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserPhotoPutSignalData $ask,
        public readonly ?int $formerFileId,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserPhotoPutSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why the photo was not set
     * @return self Answer carrying the ask back, with no former file
     */
    public static function refused(UserPhotoPutSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Photo set answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserPhotoPutSignalData::fromArray(self::requireArray($data, self::ask)),
            formerFileId: self::optionalInt($data, self::formerFileId),
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
            self::formerFileId => $this->formerFileId,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
