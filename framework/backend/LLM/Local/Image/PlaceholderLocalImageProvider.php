<?php

declare(strict_types=1);

namespace Hilos\LLM\Local\Image;

use Hilos\LLM\Contract\ImageLLMInterface;
use Hilos\LLM\DTO\ImageGenerateOptions;
use Hilos\LLM\DTO\ImageResult;
use Hilos\LLM\Local\AbstractLocalProvider;

/**
 * PlaceholderLocalImageProvider - Placeholder for local image generation.
 *
 * Not implemented yet. Local image generation (e.g. Stable Diffusion, LLaVA)
 * can be added here in the future. Always returns null.
 *
 * @extends AbstractLocalProvider
 * @implements ImageLLMInterface
 */
class PlaceholderLocalImageProvider extends AbstractLocalProvider implements ImageLLMInterface
{
    /**
     * Generate image from prompt (not implemented).
     *
     * @param string $prompt Text prompt describing the image
     * @param ImageGenerateOptions $options Generation options
     * @return null Always null (placeholder)
     */
    public function generate(string $prompt, ImageGenerateOptions $options): ?ImageResult
    {
        return null;
    }
}
