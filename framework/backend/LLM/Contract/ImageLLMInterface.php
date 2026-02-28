<?php

declare(strict_types=1);

namespace Hilos\LLM\Contract;

use Hilos\LLM\DTO\ImageGenerateOptions;
use Hilos\LLM\DTO\ImageResult;

/**
 * ImageLLMInterface - Contract for image generation LLM providers
 *
 * Unified interface for image generation from text prompt.
 * Implemented by both local and external providers.
 */
interface ImageLLMInterface
{
    /**
     * Generate image from text prompt.
     *
     * @param string $prompt Text prompt describing the image
     * @param ImageGenerateOptions $options Generation options (model, size, style)
     *
     * @return ?ImageResult Generated image data, or null on transport/format errors
     */
    public function generate(string $prompt, ImageGenerateOptions $options): ?ImageResult;
}
