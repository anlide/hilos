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
 * The person's agent → users library: the sign-in method is removed, or why not (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE} carries. The ask comes back
 * whole, so {@see AbstractUsersLibraryAgent} answers the profile from the frame alone; the refusal
 * is the three fields of {@see ActionRefusal}.
 */
final class UserIdentityUnlinkDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserIdentityUnlinkSignalData $ask The ask being answered, untouched
     * @param ?string $error Why the removal was not written, or null when it went through
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserIdentityUnlinkSignalData $ask,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserIdentityUnlinkSignalData $ask The ask being answered
     * @param ?ActionRefusal $refusal Why the removal was not written, or null when it went through
     * @return self Answer carrying the ask back
     */
    public static function to(UserIdentityUnlinkSignalData $ask, ?ActionRefusal $refusal): self
    {
        return new self($ask, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Unlink answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserIdentityUnlinkSignalData::fromArray(self::requireArray($data, self::ask)),
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
