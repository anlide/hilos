<?php

declare(strict_types=1);

namespace Hilos\LLM\External\Image;

use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\ImageLLMInterface;
use Hilos\LLM\DTO\ImageGenerateOptions;
use Hilos\LLM\DTO\ImageResult;
use Hilos\LLM\External\AbstractExternalProvider;
use Hilos\Utils\Logger;

/**
 * OpenAIImageProvider - External image provider via OpenAI DALL-E API
 *
 * Uses /v1/images/generations. Returns ImageResult with base64 or URL.
 * Compatible with OpenAI, OpenRouter, Azure OpenAI image endpoints.
 *
 * @extends AbstractExternalProvider
 * @implements ImageLLMInterface
 */
class OpenAIImageProvider extends AbstractExternalProvider implements ImageLLMInterface
{
    private const string ENDPOINT = 'v1/images/generations';

    /**
     * Generate image from text prompt.
     *
     * @param string $prompt Text description of the desired image
     * @param ImageGenerateOptions $options Model, size, timeout
     * @return ?ImageResult Generated image (base64 or URL), or null on error
     */
    public function generate(string $prompt, ImageGenerateOptions $options): ?ImageResult
    {
        $model = $options->model ?? $this->defaultModel;
        if ($model === null || $model === '') {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_MODEL_REQUIRED_IMAGE);
            return null;
        }

        $payload = [
            LLMApiConstants::KEY_MODEL => $model,
            LLMApiConstants::KEY_PROMPT => $prompt,
            LLMApiConstants::KEY_N => 1,
            LLMApiConstants::KEY_RESPONSE_FORMAT => LLMApiConstants::RESPONSE_FORMAT_B64_JSON,
            LLMApiConstants::KEY_SIZE => $options->size ?? LLMApiConstants::DEFAULT_IMAGE_SIZE,
        ];

        $url = $this->buildUrl(self::ENDPOINT);
        $rawResponse = $this->postJson($url, $payload, $options->timeoutSec);
        if ($rawResponse === null) {
            return null;
        }

        $decoded = json_decode($rawResponse, true);
        $data = $decoded[LLMApiConstants::KEY_DATA][0] ?? null;
        if (!is_array($data)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OPENAI_IMAGE_RESPONSE_INVALID . $this->truncateForLog($rawResponse)
            );
            return null;
        }

        $b64 = $data[LLMApiConstants::KEY_B64_JSON] ?? null;
        $urlResult = $data[LLMApiConstants::KEY_URL] ?? null;

        if (is_string($b64) && $b64 !== '') {
            return new ImageResult(base64: $b64);
        }

        if (is_string($urlResult) && $urlResult !== '') {
            return new ImageResult(url: $urlResult);
        }

        Logger::logAgentError(static::class, LLMApiConstants::LOG_OPENAI_IMAGE_NO_URL_OR_B64);

        return null;
    }
}
