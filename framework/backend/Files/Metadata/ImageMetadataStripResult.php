<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

final readonly class ImageMetadataStripResult
{
    private function __construct(
        public ImageMetadataOutcome $outcome,
        public ?string $tmpIndex = null,
        public ?string $contentHash = null,
        public string $reason = '',
    ) {
    }

    /** @return self Input was outside the three supported picture formats */
    public static function notAnImage(): self
    {
        return new self(ImageMetadataOutcome::NOT_AN_IMAGE);
    }

    /** @return self Input already has only the retained image data */
    public static function nothingToStrip(): self
    {
        return new self(ImageMetadataOutcome::NOTHING_TO_STRIP);
    }

    /**
     * @param string $tmpIndex Index of the cleaned temporary file
     * @param string $contentHash SHA-256 of its bytes
     * @return self Cleaned output and its fingerprint
     */
    public static function stripped(string $tmpIndex, string $contentHash): self
    {
        return new self(ImageMetadataOutcome::STRIPPED, $tmpIndex, $contentHash);
    }

    /**
     * @param string $reason Why the container could not be parsed
     * @return self Unchanged input with a diagnostic for the agent log
     */
    public static function malformed(string $reason): self
    {
        return new self(ImageMetadataOutcome::MALFORMED, reason: $reason);
    }
}
