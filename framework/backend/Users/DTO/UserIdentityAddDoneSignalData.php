<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Personal owner → caller: committed identity id or a refusal, carrying the original ask. */
final class UserIdentityAddDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string identityId = 'identityId';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserIdentityAddSignalData $ask Original ask
     * @param ?int $identityId Committed identity id, only on success
     * @param ?string $error Person-facing refusal, only on failure
     * @param ?string $errorType Internal exception class, if any
     * @param ?string $errorDetail Internal exception detail, if any
     * @throws InvalidFormatException When success and refusal material overlap or are absent
     */
    public function __construct(
        public readonly UserIdentityAddSignalData $ask,
        public readonly ?int $identityId,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
        if (($error === null && ($identityId === null || $identityId <= 0 || $errorType !== null || $errorDetail !== null))
            || ($error !== null && $identityId !== null)) {
            throw new InvalidFormatException('Identity addition must carry either an identity id or a refusal');
        }
    }

    /**
     * @param UserIdentityAddSignalData $ask Original ask
     * @param int $identityId Committed identity id
     * @return self Successful answer
     * @throws InvalidFormatException When the identity id is not positive
     */
    public static function ok(UserIdentityAddSignalData $ask, int $identityId): self
    {
        return new self($ask, $identityId, null, null, null);
    }

    /**
     * @param UserIdentityAddSignalData $ask Original ask
     * @param ActionRefusal $refusal Failure to return
     * @return self Refused answer
     * @throws InvalidFormatException When the outcome contract is invalid
     */
    public static function refused(UserIdentityAddSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Identity addition answer
     * @throws InvalidFormatException When the ask or outcome is malformed
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(self::identityId, $data)) {
            throw new InvalidFormatException('Identity addition outcome requires identityId');
        }

        return new static(
            ask: UserIdentityAddSignalData::fromArray(self::requireArray($data, self::ask)),
            identityId: self::optionalInt($data, self::identityId),
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
            self::identityId => $this->identityId,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
