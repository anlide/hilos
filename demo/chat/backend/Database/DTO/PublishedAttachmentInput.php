<?php

declare(strict_types=1);

namespace Demo\Chat\Database\DTO;

/**
 * Metadata needed to persist a file that has already moved to published storage.
 */
final readonly class PublishedAttachmentInput
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $storedName,
    ) {
    }
}
