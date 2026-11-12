<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The prior personal owner → notifications library: the push endpoint is released, or why not (HIL-1906).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE} carries. The request comes back
 * whole, so the library resumes the waiting subscribe from the frame alone; the refusal is the
 * three fields of {@see ActionRefusal}.
 */
final class UserPushReleaseDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserPushReleaseSignalData $request The request being answered, untouched
     * @param ?string $error Why the endpoint was not released, or null on success
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserPushReleaseSignalData $request,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserPushReleaseSignalData $request The completed request
     * @return self Successful release answer
     */
    public static function ok(UserPushReleaseSignalData $request): self
    {
        return new self($request, null, null, null);
    }

    /**
     * @param UserPushReleaseSignalData $request The request being answered
     * @param ActionRefusal $refusal Why the endpoint was not released
     * @return self Answer carrying the request back
     */
    public static function refused(UserPushReleaseSignalData $request, ActionRefusal $refusal): self
    {
        return new self($request, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Push-release answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserPushReleaseSignalData::fromArray(self::requireArray($data, self::request)),
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
