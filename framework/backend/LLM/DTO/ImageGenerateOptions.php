<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\LLMConstants;
use Hilos\LLM\Constants\LLMApiConstants;

/**
 * ImageGenerateOptions - Options for image generation request
 *
 * Used for DALL-E and compatible image APIs.
 *
 * @extends BaseDTO
 */
class ImageGenerateOptions extends BaseDTO
{
    /**
     * Creates image generate options instance.
     *
     * @param ?string $model Model name (e.g. dall-e-3)
     * @param ?string $size Image dimensions (e.g. 1024x1024)
     * @param float $timeoutSec Request timeout in seconds
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly ?string $size = null,
        public readonly float $timeoutSec = LLMConstants::DEFAULT_TIMEOUT_SEC,
    ) {
    }

    /**
     * Converts options to array for API request.
     *
     * @return array<string, mixed> Model, size, timeout keys
     */
    public function toArray(): array
    {
        return array_filter([
            LLMApiConstants::KEY_MODEL => $this->model,
            LLMApiConstants::KEY_SIZE => $this->size,
            LLMApiConstants::KEY_TIMEOUT_SEC => $this->timeoutSec,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Creates options from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static Options instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            model: isset($data[LLMApiConstants::KEY_MODEL]) ? (string) $data[LLMApiConstants::KEY_MODEL] : null,
            size: isset($data[LLMApiConstants::KEY_SIZE]) ? (string) $data[LLMApiConstants::KEY_SIZE] : null,
            timeoutSec: isset($data[LLMApiConstants::KEY_TIMEOUT_SEC]) ? (float) $data[LLMApiConstants::KEY_TIMEOUT_SEC] : LLMConstants::DEFAULT_TIMEOUT_SEC,
        );
    }
}
