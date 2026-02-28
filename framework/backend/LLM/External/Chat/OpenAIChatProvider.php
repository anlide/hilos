<?php

declare(strict_types=1);

namespace Hilos\LLM\External\Chat;

use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\ChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\External\AbstractExternalProvider;
use Hilos\Utils\Logger;

/**
 * OpenAIChatProvider - External chat provider via OpenAI-compatible API
 *
 * Uses /v1/chat/completions. Compatible with OpenAI, OpenRouter, Azure OpenAI, etc.
 *
 * @extends AbstractExternalProvider
 * @implements ChatLLMInterface
 */
class OpenAIChatProvider extends AbstractExternalProvider implements ChatLLMInterface
{
    private const string ENDPOINT = 'v1/chat/completions';

    /**
     * Generate text completion from chat messages.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @param ChatGenerateOptions $options Model, temperature, timeout, maxTokens
     * @return ?string Generated text, or null on error
     */
    public function generate(array $messages, ChatGenerateOptions $options): ?string
    {
        $model = $options->model ?? $this->defaultModel;
        if ($model === null || $model === '') {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_MODEL_REQUIRED_OPENAI);
            return null;
        }

        $apiMessages = [];
        foreach ($messages as $msg) {
            $apiMessages[] = Message::toProviderFormat($msg);
        }

        $payload = [
            LLMApiConstants::KEY_MODEL => $model,
            LLMApiConstants::KEY_MESSAGES => $apiMessages,
            LLMApiConstants::KEY_STREAM => false,
            LLMApiConstants::KEY_TEMPERATURE => $options->temperature,
        ];

        if ($options->maxTokens !== null) {
            $payload[LLMApiConstants::KEY_MAX_TOKENS] = $options->maxTokens;
        }

        $url = $this->buildUrl(self::ENDPOINT);
        $rawResponse = $this->postJson($url, $payload, $options->timeoutSec);
        if ($rawResponse === null) {
            return null;
        }

        $decoded = json_decode($rawResponse, true);
        $content = $decoded[LLMApiConstants::KEY_CHOICES][0][LLMApiConstants::KEY_MESSAGE][LLMApiConstants::KEY_CONTENT] ?? null;
        if (!is_string($content)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OPENAI_RESPONSE_INVALID . $this->truncateForLog($rawResponse)
            );
            return null;
        }

        return trim($content);
    }
}
