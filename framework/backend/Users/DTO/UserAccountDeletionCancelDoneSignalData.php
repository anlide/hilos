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
 * The person's agent → users library: the deletion request is canceled, or was not standing (HIL-1409).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE} carries. The ask comes
 * back whole, so {@see AbstractUsersLibraryAgent} continues and answers from the frame alone. A
 * request that no longer stood is not a refusal: nothing was canceled. The refusal is the three
 * fields of {@see ActionRefusal}.
 */
final class UserAccountDeletionCancelDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string canceled = 'canceled';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserAccountDeletionCancelSignalData $ask The ask being answered, untouched
     * @param bool $canceled Whether this ask canceled a standing request
     * @param ?string $error Why nothing was looked at, or null when the request was looked at
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserAccountDeletionCancelSignalData $ask,
        public readonly bool $canceled,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserAccountDeletionCancelSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why nothing was looked at
     * @return self Answer carrying the ask back, with nothing canceled
     */
    public static function refused(UserAccountDeletionCancelSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, false, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Deletion cancel answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserAccountDeletionCancelSignalData::fromArray(self::requireArray($data, self::ask)),
            canceled: self::requireBool($data, self::canceled),
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
            self::canceled => $this->canceled,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
