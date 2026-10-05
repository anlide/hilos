<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\LLM\Constants\LLMApiConstants;

/**
 * ChatGenerateOptions - Options for chat completion request.
 *
 * @extends BaseDTO
 */
class ChatGenerateOptions extends BaseDTO
{
    /**
     * Creates chat generate options instance.
     *
     * @param ?string $model Model name (e.g. gpt-4o-mini, qwen2.5:3b)
     * @param ?float $temperature Sampling temperature (0.0 = deterministic, null = model default)
     * @param float $timeoutSec Request timeout in seconds
     * @param ?int $maxTokens Maximum tokens to generate
     * @param ?array<string, mixed> $responseFormat Response format for OpenAI-compatible APIs
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly ?float $temperature = null,
        public readonly float $timeoutSec = LLMConstants::DEFAULT_TIMEOUT_SEC,
        public readonly ?int $maxTokens = null,
        public readonly ?array $responseFormat = null,
    ) {
    }

    /**
     * Converts options to array for API request.
     *
     * @return array<string, mixed> Model, temperature, timeout, maxTokens keys
     */
    public function toArray(): array
    {
        return array_filter([
            LLMApiConstants::KEY_MODEL => $this->model,
            LLMApiConstants::KEY_TEMPERATURE => $this->temperature,
            LLMApiConstants::KEY_TIMEOUT_SEC => $this->timeoutSec,
            LLMApiConstants::KEY_MAX_TOKENS_CAMEL => $this->maxTokens,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Creates options from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static Options instance
     * @throws InvalidFormatException When the payload carries no timeoutSec or an invalid type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            model: self::optionalString($data, LLMApiConstants::KEY_MODEL),
            temperature: self::optionalFloat($data, LLMApiConstants::KEY_TEMPERATURE),
            timeoutSec: self::requireFloat($data, LLMApiConstants::KEY_TIMEOUT_SEC),
            maxTokens: self::optionalInt($data, LLMApiConstants::KEY_MAX_TOKENS_CAMEL),
            responseFormat: self::optionalArray($data, 'responseFormat'),
        );
    }
}
