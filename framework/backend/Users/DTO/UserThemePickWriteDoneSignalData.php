<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** The person's agent → the coordinator: the theme choice was written, or why not (HIL-1427). */
final class UserThemePickWriteDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserThemePickWriteSignalData $ask The ask being answered, untouched
     * @param ?string $error Why the choice was not written, or null on success
     * @param ?string $errorType Class name of the failure, or null on success
     * @param ?string $errorDetail Original failure message, or null on success
     */
    public function __construct(
        public readonly UserThemePickWriteSignalData $ask,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserThemePickWriteSignalData $ask The ask being answered
     * @param ?ActionRefusal $refusal Why the choice was not written, or null on success
     * @return self Answer carrying the ask back
     */
    public static function to(UserThemePickWriteSignalData $ask, ?ActionRefusal $refusal): self
    {
        return new self($ask, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Theme choice answer
     * @throws InvalidFormatException When the ask or outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserThemePickWriteSignalData::fromArray(self::requireArray($data, self::ask)),
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
