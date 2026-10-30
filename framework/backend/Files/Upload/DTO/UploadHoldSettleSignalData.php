<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\Upload\UploadHoldOutcome;

/** Consumer agent → uploads owner: settle one attempt without changing the publication request. */
final class UploadHoldSettleSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string uploadHoldId = 'uploadHoldId';
    public const string outcome = 'outcome';

    /**
     * @param string $acceptKey Connection whose uploads were held
     * @param string $uploadHoldId Original attempt token
     * @param UploadHoldOutcome $outcome Final approval or release
     * @throws InvalidFormatException When the connection or token is empty
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $uploadHoldId,
        public readonly UploadHoldOutcome $outcome,
    ) {
        if ($acceptKey === '' || $uploadHoldId === '') {
            throw new InvalidFormatException('An upload hold settlement requires a connection and an attempt token.');
        }
    }

    /** @return array<string, string> Internal transport payload */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::uploadHoldId => $this->uploadHoldId,
            self::outcome => $this->outcome->value,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal transport payload
     * @return static Typed settlement
     * @throws InvalidFormatException When a required field is missing, mistyped, empty or names an unknown outcome
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::acceptKey),
            self::requireString($data, self::uploadHoldId),
            UploadHoldOutcome::tryFrom(self::requireString($data, self::outcome))
                ?? throw new InvalidFormatException('Unknown upload hold outcome.'),
        );
    }
}
