<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\LLM\Constants\LLMApiConstants;

/**
 * ImageResult - Result of image generation
 *
 * Contains generated image as URL (e.g. temporary URL) or base64-encoded data.
 * At least one of url or base64 should be set for valid result.
 *
 * @extends BaseDTO
 */
class ImageResult extends BaseDTO
{
    /**
     * @param ?string $url Temporary URL to the generated image
     * @param ?string $base64 Base64-encoded image data
     */
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $base64 = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            LLMApiConstants::KEY_URL => $this->url,
            LLMApiConstants::KEY_BASE64 => $this->base64,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new self(
            url: isset($data[LLMApiConstants::KEY_URL]) ? (string) $data[LLMApiConstants::KEY_URL] : null,
            base64: isset($data[LLMApiConstants::KEY_BASE64]) ? (string) $data[LLMApiConstants::KEY_BASE64] : null,
        );
    }

    /**
     * Get image data (base64 preferred, fallback to URL).
     *
     * @return string Base64 string, URL, or empty string if neither set
     */
    public function getData(): string
    {
        if ($this->base64 !== null && $this->base64 !== '') {
            return $this->base64;
        }

        if ($this->url !== null && $this->url !== '') {
            return $this->url;
        }

        return '';
    }
}
