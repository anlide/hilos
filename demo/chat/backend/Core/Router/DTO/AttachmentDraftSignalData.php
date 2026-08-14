<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Browser-facing payload for one uploaded attachment draft.
 */
final class AttachmentDraftSignalData extends BaseDTO
{
    public const string draftId = 'draftId';
    public const string filename = 'filename';
    public const string mimeType = 'mimeType';
    public const string size = 'size';
    public const string uploadedAt = 'uploadedAt';

    public function __construct(
        public readonly string $draftId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $uploadedAt,
    ) {
    }

    /**
     * @return array{draftId: string, filename: string, mimeType: string, size: int, uploadedAt: int}
     */
    public function toArray(): array
    {
        return [
            self::draftId => $this->draftId,
            self::filename => $this->filename,
            self::mimeType => $this->mimeType,
            self::size => $this->size,
            self::uploadedAt => $this->uploadedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     * @throws InvalidFormatException When the payload is missing a field the draft is listed by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            draftId: self::requireString($data, self::draftId),
            filename: self::requireString($data, self::filename),
            mimeType: self::requireString($data, self::mimeType),
            size: self::requireInt($data, self::size),
            uploadedAt: self::requireInt($data, self::uploadedAt),
        );
    }
}
