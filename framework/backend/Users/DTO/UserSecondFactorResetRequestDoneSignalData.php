<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Personal owner → caller: committed second-factor material or a refusal. */
final class UserSecondFactorResetRequestDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string resetId = 'resetId';
    public const string effectiveAt = 'effectiveAt';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorResetRequestSignalData $ask Original ask
     * @param ?int $resetId Committed result, only on success
     * @param ?int $effectiveAt Committed result, only on success
     * @param ?string $error Person-facing refusal
     * @param ?string $errorType Internal exception class
     * @param ?string $errorDetail Internal exception detail
     * @throws InvalidFormatException When success and refusal material overlap or are absent
     */
    public function __construct(
        public readonly UserSecondFactorResetRequestSignalData $ask,
        public readonly ?int $resetId,
        public readonly ?int $effectiveAt,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
        if (($error === null && ($resetId === null || $resetId <= 0 || $effectiveAt === null || $effectiveAt <= 0
                || $errorType !== null || $errorDetail !== null))
            || ($error !== null && ($resetId !== null || $effectiveAt !== null))) {
            throw new InvalidFormatException('Second-factor creation must carry either committed data or a refusal');
        }
    }

    /**
     * @param UserSecondFactorResetRequestSignalData $ask Original ask
     * @param ActionRefusal $refusal Failure to return
     * @return self Refused answer
     * @throws InvalidFormatException When the outcome contract is invalid
     */
    public static function refused(UserSecondFactorResetRequestSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, null, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Second-factor creation answer
     * @throws InvalidFormatException When the ask or outcome is malformed
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(self::resetId, $data)) {
            throw new InvalidFormatException('Second-factor outcome requires resetId');
        }
        if (!array_key_exists(self::effectiveAt, $data)) {
            throw new InvalidFormatException('Second-factor outcome requires effectiveAt');
        }
        return new static(
            ask: UserSecondFactorResetRequestSignalData::fromArray(self::requireArray($data, self::ask)),
            resetId: self::optionalInt($data, self::resetId),
            effectiveAt: self::optionalInt($data, self::effectiveAt),
            error: self::requireNullableString($data, self::error),
            errorType: self::requireNullableString($data, self::errorType),
            errorDetail: self::requireNullableString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::ask => $this->ask->toArray(),
            self::resetId => $this->resetId,
            self::effectiveAt => $this->effectiveAt,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
