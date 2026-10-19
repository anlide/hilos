<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\Upload\UploadFrame;

/** Browser demand, stamped with the real socket identity before entering the master queue. */
final class UploadCreditRequestSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Real socket identity
     * @param string $clientUploadId Socket-local upload identity
     * @param int $bytes Full declared file size
     * @throws InvalidFormatException When identities or size are invalid
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $clientUploadId,
        public readonly int $bytes,
    ) {
        if ($acceptKey === '' || !UploadFrame::isValidId($clientUploadId) || $bytes < 0) {
            throw new InvalidFormatException('Upload credit needs a socket identity, valid upload id and nonnegative file size');
        }
    }

    /** @return array{acceptKey: string, clientUploadId: string, bytes: int} Internal service payload */
    public function toArray(): array
    {
        return ['acceptKey' => $this->acceptKey, 'clientUploadId' => $this->clientUploadId, 'bytes' => $this->bytes];
    }

    /**
     * @param array<string, mixed> $data Internal service payload
     * @return static Validated demand
     * @throws InvalidFormatException When fields are absent, invalid or have the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'acceptKey'),
            self::requireString($data, 'clientUploadId'),
            self::requireInt($data, 'bytes'),
        );
    }
}
