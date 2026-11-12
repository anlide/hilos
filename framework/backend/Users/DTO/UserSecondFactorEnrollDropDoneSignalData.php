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
 * The person's agent → users library: the unfinished enrolments are dropped, or why not (HIL-1903).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP_DONE} carries. The ask comes
 * back whole, so {@see AbstractUsersLibraryAgent} creates the new enrolment and answers the press
 * from the frame alone; the refusal is the three fields of {@see ActionRefusal}.
 */
final class UserSecondFactorEnrollDropDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorEnrollDropSignalData $ask The ask being answered, untouched
     * @param ?string $error Why the enrolments were not dropped, or null when they were
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserSecondFactorEnrollDropSignalData $ask,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserSecondFactorEnrollDropSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why the enrolments were not dropped
     * @return self Answer carrying the ask back
     */
    public static function refused(UserSecondFactorEnrollDropSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Enrolment drop answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserSecondFactorEnrollDropSignalData::fromArray(self::requireArray($data, self::ask)),
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
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
