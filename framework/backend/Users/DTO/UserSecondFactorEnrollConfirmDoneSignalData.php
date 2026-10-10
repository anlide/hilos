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
 * The person's agent → users library: the app is connected, or why not (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE} carries. The ask
 * comes back whole, so {@see AbstractUsersLibraryAgent} continues the enrolment from the frame
 * alone: a code that missed is told to the session holder on the way in, and the person's first
 * app gets its backup codes from the library, which creates them. The refusal is the three fields
 * of {@see ActionRefusal}.
 */
final class UserSecondFactorEnrollConfirmDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string missed = 'missed';
    public const string first = 'first';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorEnrollConfirmSignalData $ask The ask being answered, untouched
     * @param bool $missed Whether the code was checked and matched nothing
     * @param bool $first Whether the confirmed app is the person's first
     * @param ?string $error Why the app was not connected, or null when it was
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserSecondFactorEnrollConfirmSignalData $ask,
        public readonly bool $missed,
        public readonly bool $first,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserSecondFactorEnrollConfirmSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why nothing was checked
     * @return self Answer carrying the ask back, with no miss
     */
    public static function refused(UserSecondFactorEnrollConfirmSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, false, false, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Enrolment confirmation answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserSecondFactorEnrollConfirmSignalData::fromArray(self::requireArray($data, self::ask)),
            missed: self::requireBool($data, self::missed),
            first: self::requireBool($data, self::first),
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
            self::missed => $this->missed,
            self::first => $this->first,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
