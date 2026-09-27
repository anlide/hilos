<?php

declare(strict_types=1);

namespace Hilos\Files\Image\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\Image\ImageVariant;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/** Files library → images agent: draw a copy, carrying the HTTP request that waits for it. */
final class ImageRenderSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string fileId = 'fileId';
    public const string storedName = 'storedName';
    public const string mimeType = 'mimeType';
    public const string variant = 'variant';
    public const string retry = 'retry';

    /**
     * @param HttpRequestDTO $request Parked browser request, including its origin node
     * @param int $fileId Original registry row
     * @param string $storedName Original's storage name
     * @param string $mimeType Original's registered type
     * @param string $variant Declared copy name
     * @param bool $retry The library found no live copy after ready; this request must never be answered ready
     * @throws InvalidFormatException When a file field or variant name is malformed
     */
    public function __construct(
        public readonly HttpRequestDTO $request,
        public readonly int $fileId,
        public readonly string $storedName,
        public readonly string $mimeType,
        public readonly string $variant,
        public readonly bool $retry,
    ) {
        if ($fileId <= 0 || $storedName === '' || $mimeType === '' || preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1) {
            throw new InvalidFormatException('Image render needs a positive file id, storage name, MIME type and valid variant name');
        }
    }

    /** @return array<string, mixed> Wire payload with the whole waiting request */
    public function toArray(): array
    {
        return [
            self::request => $this->request->toArray(),
            self::fileId => $this->fileId,
            self::storedName => $this->storedName,
            self::mimeType => $this->mimeType,
            self::variant => $this->variant,
            self::retry => $this->retry,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored request
     * @throws InvalidFormatException When a required field is absent, mistyped or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            HttpRequestDTO::fromArray(self::requireArray($data, self::request)),
            self::requireInt($data, self::fileId),
            self::requireString($data, self::storedName),
            self::requireString($data, self::mimeType),
            self::requireString($data, self::variant),
            self::requireBool($data, self::retry),
        );
    }
}
