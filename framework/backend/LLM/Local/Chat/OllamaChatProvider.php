<?php

declare(strict_types=1);

namespace Hilos\LLM\Local\Chat;

use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\ChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Local\AbstractLocalProvider;
use Hilos\Utils\Logger;

/**
 * OllamaChatProvider - Local chat provider via Ollama API
 *
 * Uses Ollama /api/generate (completion style). Converts chat messages
 * to a single prompt string for completion-style API.
 *
 * @extends AbstractLocalProvider
 * @implements ChatLLMInterface
 */
class OllamaChatProvider extends AbstractLocalProvider implements ChatLLMInterface
{
    private const string ENDPOINT = 'api/generate';

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
            Logger::logAgentError(static::class, LLMApiConstants::LOG_MODEL_REQUIRED_OLLAMA);
            return null;
        }

        $prompt = $this->messagesToPrompt($messages);
        $url = $this->buildUrl(self::ENDPOINT);

        $payload = [
            LLMApiConstants::KEY_MODEL => $model,
            LLMApiConstants::KEY_PROMPT => $prompt,
            LLMApiConstants::KEY_STREAM => false,
            LLMApiConstants::KEY_OPTIONS => [
                LLMApiConstants::KEY_TEMPERATURE => $options->temperature,
            ],
        ];

        if ($options->maxTokens !== null) {
            $payload[LLMApiConstants::KEY_OPTIONS][LLMApiConstants::KEY_NUM_PREDICT] = $options->maxTokens;
        }

        $rawResponse = $this->postJson($url, $payload, $options->timeoutSec);
        if ($rawResponse === null) {
            return null;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded) || !is_string($decoded[LLMApiConstants::KEY_RESPONSE] ?? null)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID . $this->truncateForLog($rawResponse)
            );
            return null;
        }

        return trim($decoded[LLMApiConstants::KEY_RESPONSE]);
    }

    /**
     * Convert chat messages to single prompt (Ollama uses completion format).
     *
     * Formats as "System: ...\n\nUser: ...\n\nAssistant: " for completion.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @return string Single prompt string for /api/generate
     */
    private function messagesToPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $m = Message::toProviderFormat($msg);
            $role = $m[LLMApiConstants::KEY_ROLE];
            $content = $m[LLMApiConstants::KEY_CONTENT];
            if ($content === '') {
                continue;
            }
            $parts[] = match ($role) {
                Message::ROLE_SYSTEM => LLMApiConstants::OLLAMA_PREFIX_SYSTEM . $content,
                Message::ROLE_USER => LLMApiConstants::OLLAMA_PREFIX_USER . $content,
                Message::ROLE_ASSISTANT => LLMApiConstants::OLLAMA_PREFIX_ASSISTANT . $content,
                default => $content,
            };
        }

        return implode(LLMApiConstants::OLLAMA_SEPARATOR, $parts) . LLMApiConstants::OLLAMA_ASSISTANT_SUFFIX;
    }
}
