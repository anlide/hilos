<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Personal owner → caller: committed second-factor material or a refusal. */
final class UserSecondFactorEnrollStartDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string enrollment = 'enrollment';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorEnrollStartSignalData $ask Original ask
     * @param ?SecondFactorEnrollmentData $enrollment Committed result, only on success
     * @param ?string $error Person-facing refusal
     * @param ?string $errorType Internal exception class
     * @param ?string $errorDetail Internal exception detail
     * @throws InvalidFormatException When success and refusal material overlap or are absent
     */
    public function __construct(
        public readonly UserSecondFactorEnrollStartSignalData $ask,
        public readonly ?SecondFactorEnrollmentData $enrollment,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
        if (($error === null && ($enrollment === null || $errorType !== null || $errorDetail !== null))
            || ($error !== null && ($enrollment !== null))) {
            throw new InvalidFormatException('Second-factor creation must carry either committed data or a refusal');
        }
    }

    /**
     * @param UserSecondFactorEnrollStartSignalData $ask Original ask
     * @param ActionRefusal $refusal Failure to return
     * @return self Refused answer
     * @throws InvalidFormatException When the outcome contract is invalid
     */
    public static function refused(UserSecondFactorEnrollStartSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Second-factor creation answer
     * @throws InvalidFormatException When the ask or outcome is malformed
     */
    public static function fromArray(array $data): static
    {
        $enrollment = self::requireNullableArray($data, self::enrollment);
        return new static(
            ask: UserSecondFactorEnrollStartSignalData::fromArray(self::requireArray($data, self::ask)),
            enrollment: $enrollment === null ? null : SecondFactorEnrollmentData::fromArray($enrollment),
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
            self::enrollment => $this->enrollment?->toArray(),
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
