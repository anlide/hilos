<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** The person's agent returns the time-zone write ask and its public outcome. */
final class UserTimeZoneWriteDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserTimeZoneWriteSignalData $ask The ask being answered, unchanged
     * @param ?string $error Public reason for refusal, or null on success
     * @param ?string $errorType Internal class name of the failure, or null on success
     * @param ?string $errorDetail Internal original failure message, or null on success
     */
    public function __construct(
        public readonly UserTimeZoneWriteSignalData $ask,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserTimeZoneWriteSignalData $ask The ask being answered
     * @param ?ActionRefusal $refusal Refusal to write the time zone, or null on success
     * @return self Answer carrying the same ask back
     */
    public static function to(UserTimeZoneWriteSignalData $ask, ?ActionRefusal $refusal): self
    {
        return new self($ask, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored time-zone write answer
     * @throws InvalidFormatException When the ask or outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserTimeZoneWriteSignalData::fromArray(self::requireArray($data, self::ask)),
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
