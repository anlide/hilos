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
 * The person's agent → users library: the app-code lock is lifted, or why not (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE} carries. The request comes
 * back whole, so {@see AbstractUsersLibraryAgent} answers the parked command from the frame alone;
 * the refusal is the three fields of {@see ActionRefusal}.
 */
final class UserSecondFactorUnlockDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string lockedUntil = 'lockedUntil';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorUnlockSignalData $request The request being answered, untouched
     * @param ?string $lockedUntil End of the lifted lock (SQL datetime) when one was in force, or null when none was
     * @param ?string $error Why the lock was not lifted, or null when it was
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserSecondFactorUnlockSignalData $request,
        public readonly ?string $lockedUntil,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserSecondFactorUnlockSignalData $request The request being answered
     * @param ActionRefusal $refusal Why the lock was not lifted
     * @return self Answer carrying the request back
     */
    public static function refused(UserSecondFactorUnlockSignalData $request, ActionRefusal $refusal): self
    {
        return new self($request, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Lock lift answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserSecondFactorUnlockSignalData::fromArray(self::requireArray($data, self::request)),
            lockedUntil: self::optionalString($data, self::lockedUntil),
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
            self::lockedUntil => $this->lockedUntil,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
