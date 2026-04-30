<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadCompleteSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param array<string, mixed> $attachmentDraft Draft row created from uploaded file
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $filename,
        public readonly array $attachmentDraft,
    ) {
    }

    public function toArray(): array
    {
        return [
            'uploadId' => $this->uploadId,
            'filename' => $this->filename,
            'attachmentDraft' => $this->attachmentDraft,
        ];
    }

    public static function fromArray(array $data): static
    {
        $attachmentDraft = $data['attachmentDraft'] ?? [];

        return new static(
            uploadId: (string)($data['uploadId'] ?? ''),
            filename: (string)($data['filename'] ?? ''),
            attachmentDraft: is_array($attachmentDraft) ? $attachmentDraft : [],
        );
    }
}
