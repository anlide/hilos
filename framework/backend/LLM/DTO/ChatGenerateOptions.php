<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\LLMConstants;
use Hilos\LLM\Constants\LLMApiConstants;

/**
 * ChatGenerateOptions - Options for chat completion request
 *
 * @extends BaseDTO
 */
class ChatGenerateOptions extends BaseDTO
{
    /**
     * @param ?string $model Model name (e.g. gpt-4o-mini, qwen2.5:3b)
     * @param float $temperature Sampling temperature (0.0 = deterministic)
     * @param float $timeoutSec Request timeout in seconds
     * @param ?int $maxTokens Maximum tokens to generate
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly float $temperature = 0.0,
        public readonly float $timeoutSec = LLMConstants::DEFAULT_TIMEOUT_SEC,
        public readonly ?int $maxTokens = null,
    ) {
    }

    /** @return array<string, mixed> */
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
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new self(
            model: isset($data[LLMApiConstants::KEY_MODEL]) ? (string) $data[LLMApiConstants::KEY_MODEL] : null,
            temperature: isset($data[LLMApiConstants::KEY_TEMPERATURE]) ? (float) $data[LLMApiConstants::KEY_TEMPERATURE] : 0.0,
            timeoutSec: isset($data[LLMApiConstants::KEY_TIMEOUT_SEC]) ? (float) $data[LLMApiConstants::KEY_TIMEOUT_SEC] : LLMConstants::DEFAULT_TIMEOUT_SEC,
            maxTokens: isset($data[LLMApiConstants::KEY_MAX_TOKENS_CAMEL]) ? (int) $data[LLMApiConstants::KEY_MAX_TOKENS_CAMEL] : null,
        );
    }
}
