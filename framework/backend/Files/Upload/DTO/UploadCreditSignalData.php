<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Cumulative permission addressed to the socket that declared the upload. */
final class UploadCreditSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $clientUploadId Socket-local upload identity
     * @param int $sendUpToBytes Cumulative permitted file offset
     * @throws InvalidFormatException When the identity or boundary is invalid
     */
    public function __construct(public readonly string $clientUploadId, public readonly int $sendUpToBytes)
    {
        if ($clientUploadId === '' || $sendUpToBytes < 0) {
            throw new InvalidFormatException('Upload credit needs an identity and a nonnegative boundary');
        }
    }

    /** @return array{clientUploadId: string, sendUpToBytes: int} Browser permission */
    public function toArray(): array
    {
        return ['clientUploadId' => $this->clientUploadId, 'sendUpToBytes' => $this->sendUpToBytes];
    }

    /**
     * @param array<string, mixed> $data Browser permission
     * @return static Validated permission
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'clientUploadId'), self::requireInt($data, 'sendUpToBytes'));
    }
}
