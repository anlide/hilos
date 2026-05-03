<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Hilos\BaseDTO;

/**
 * Browser-facing payload for one uploaded attachment draft.
 */
final class AttachmentDraftSignalData extends BaseDTO
{
    public function __construct(
        public readonly string $draftId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $uploadedAt,
    ) {
    }

    /**
     * Builds the wire payload DTO from one runtime draft item.
     *
     * @param AttachmentDraft $draft Runtime draft item
     * @return static Wire payload DTO
     */
    public static function fromDraft(AttachmentDraft $draft): static
    {
        return new static(
            draftId: $draft->draftId,
            filename: $draft->originalFilename,
            mimeType: $draft->mimeType,
            size: $draft->size,
            uploadedAt: $draft->uploadedAt,
        );
    }

    /**
     * Builds wire payload rows for a selected runtime draft collection.
     *
     * @param AttachmentDrafts $drafts Runtime draft collection
     * @return list<array{draftId: string, filename: string, mimeType: string, size: int, uploadedAt: int}>
     */
    public static function listFromDrafts(AttachmentDrafts $drafts): array
    {
        $rows = [];
        foreach ($drafts as $draft) {
            $rows[] = self::fromDraft($draft)->toArray();
        }

        return $rows;
    }

    /**
     * @return array{draftId: string, filename: string, mimeType: string, size: int, uploadedAt: int}
     */
    public function toArray(): array
    {
        return [
            'draftId' => $this->draftId,
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'uploadedAt' => $this->uploadedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            draftId: (string)($data['draftId'] ?? ''),
            filename: (string)($data['filename'] ?? ''),
            mimeType: (string)($data['mimeType'] ?? ''),
            size: (int)($data['size'] ?? 0),
            uploadedAt: (int)($data['uploadedAt'] ?? 0),
        );
    }
}
