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
 * The person's agent → users library: the removal is carried out, or no longer stood (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE} carries. The request
 * comes back whole, so {@see AbstractUsersLibraryAgent} tells the session holder the factor is
 * gone, mails the person and fans the section from the frame alone - only when this frame carried
 * the removal out. The refusal is the three fields of {@see ActionRefusal}, and only a log line
 * reads it: nobody is waiting on a sweep.
 */
final class UserSecondFactorResetDueDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string carriedOut = 'carriedOut';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorResetDueSignalData $request The request being answered, untouched
     * @param bool $carriedOut Whether this frame carried the removal out and took the factor
     * @param ?string $error Why nothing was looked at, or null when the removal was looked at
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserSecondFactorResetDueSignalData $request,
        public readonly bool $carriedOut,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserSecondFactorResetDueSignalData $request The request being answered
     * @param ActionRefusal $refusal Why nothing was looked at
     * @return self Answer carrying the request back, with nothing carried out
     */
    public static function refused(UserSecondFactorResetDueSignalData $request, ActionRefusal $refusal): self
    {
        return new self($request, false, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Due removal answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserSecondFactorResetDueSignalData::fromArray(self::requireArray($data, self::request)),
            carriedOut: self::requireBool($data, self::carriedOut),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::request => $this->request->toArray(),
            self::carriedOut => $this->carriedOut,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
