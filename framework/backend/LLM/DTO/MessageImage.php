<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\Core\Exception\InvalidFormatException;

/** An encoded picture attached to one chat message. */
final readonly class MessageImage
{
    public const string KEY_MIME_TYPE = 'mimeType';

    public const string KEY_DATA = 'data';

    public function __construct(
        public string $mimeType,
        public string $base64,
    ) {
    }

    /**
     * @return array{mimeType: string, data: string} Serialized image
     */
    public function toArray(): array
    {
        return [
            self::KEY_MIME_TYPE => $this->mimeType,
            self::KEY_DATA => $this->base64,
        ];
    }

    /**
     * @param array<string, mixed> $data Serialized image
     * @return self Restored image
     * @throws InvalidFormatException When either image field is absent or malformed
     */
    public static function fromArray(array $data): self
    {
        $mimeType = $data[self::KEY_MIME_TYPE] ?? null;
        $base64 = $data[self::KEY_DATA] ?? null;
        if (!is_string($mimeType) || !is_string($base64)) {
            throw new InvalidFormatException('Message image needs a MIME type and base64 data');
        }

        return new self($mimeType, $base64);
    }
}
