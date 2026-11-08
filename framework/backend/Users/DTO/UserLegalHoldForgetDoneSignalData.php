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
 * Test-only person's agent → users library: the later acceptances are removed, or why not (HIL-1899).
 *
 * What {@see HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE} carries. The request comes
 * back whole, so {@see AbstractUsersLibraryAgent} answers the parked command from the frame alone;
 * the refusal is the three fields of {@see ActionRefusal}.
 */
final class UserLegalHoldForgetDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserLegalHoldForgetSignalData $request The request being answered, untouched
     * @param ?string $error Why the later acceptances were not removed, or null on success
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserLegalHoldForgetSignalData $request,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserLegalHoldForgetSignalData $request The completed request
     * @return self Successful removal answer
     */
    public static function ok(UserLegalHoldForgetSignalData $request): self
    {
        return new self($request, null, null, null);
    }

    /**
     * @param UserLegalHoldForgetSignalData $request The request being answered
     * @param ActionRefusal $refusal Why the later acceptances were not removed
     * @return self Answer carrying the request back
     */
    public static function refused(UserLegalHoldForgetSignalData $request, ActionRefusal $refusal): self
    {
        return new self($request, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Test-only acceptance removal answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserLegalHoldForgetSignalData::fromArray(self::requireArray($data, self::request)),
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
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
