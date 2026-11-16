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
 * The person's agent → users library: the passkey's use is recorded, or why not (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE} carries. The ask comes back whole,
 * so {@see AbstractUsersLibraryAgent} signs the person in or records the confirmed step, as the
 * operation of the ask says, from the frame alone; the refusal is the three fields of
 * {@see ActionRefusal}.
 */
final class UserPasskeyUseDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';
    public const string erasing = 'erasing';

    /**
     * @param UserPasskeyUseSignalData $ask The ask being answered, untouched
     * @param ?string $error Why the use was not written, or null when it went through
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     * @param bool $erasing Whether the refusal is the domain refusal of an account being erased
     * @throws InvalidFormatException When an erasing answer carries no refusal
     */
    public function __construct(
        public readonly UserPasskeyUseSignalData $ask,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
        public readonly bool $erasing = false,
    ) {
        if ($erasing && $error === null) {
            throw new InvalidFormatException('An erasing passkey answer must carry a refusal');
        }
    }

    /**
     * @param UserPasskeyUseSignalData $ask The ask being answered
     * @param ?ActionRefusal $refusal Why the use was not written, or null when it went through
     * @param bool $erasing Whether the account's erasure refused the use
     * @return self Answer carrying the ask back
     * @throws InvalidFormatException When an erasing answer carries no refusal
     */
    public static function to(UserPasskeyUseSignalData $ask, ?ActionRefusal $refusal, bool $erasing = false): self
    {
        return new self($ask, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail, $erasing);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Passkey use answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserPasskeyUseSignalData::fromArray(self::requireArray($data, self::ask)),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
            erasing: self::requireBool($data, self::erasing),
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
            self::erasing => $this->erasing,
        ];
    }
}
