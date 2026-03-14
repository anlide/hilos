<?php

declare(strict_types=1);

namespace Hilos\LLM\External\Chat;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\HttpConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Logger;

/**
 * AsyncOpenAIChatProvider - Non-blocking external chat provider via OpenAI-compatible API
 *
 * Uses /v1/chat/completions over AsyncHttpClient with HTTPS.
 * Compatible with OpenAI, OpenRouter, Azure OpenAI, etc.
 *
 * @implements AsyncChatLLMInterface
 */
class AsyncOpenAIChatProvider implements AsyncChatLLMInterface
{
    private AsyncHttpClient $httpClient;
    private ?string $defaultModel;
    private string $apiKey;

    /**
     * Creates OpenAI-compatible chat provider.
     *
     * @param string $baseUrl Base URL (e.g. https://api.openai.com)
     * @param string $apiKey API key for Bearer auth
     * @param ?string $defaultModel Default model name
     */
    public function __construct(string $baseUrl, string $apiKey, ?string $defaultModel = null)
    {
        [$host, $port, $path] = $this->parseUrl($baseUrl);
        $this->httpClient = new AsyncHttpClient($host, $port, $path, useTls: true);
        $this->defaultModel = $defaultModel;
        $this->apiKey = $apiKey;
    }

    /**
     * Parses base URL into host, port and path.
     *
     * @param string $url Base URL of API (e.g. https://api.openai.com)
     * @return array{0: string, 1: int, 2: string} [host, port, path]
     */
    private function parseUrl(string $url): array
    {
        $url = rtrim($url, '/');
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'api.openai.com';
        $port = $parsed['port'] ?? 443;
        $basePath = ($parsed['path'] ?? '') ?: '/v1';
        $path = rtrim($basePath, '/') . '/chat/completions';

        return [$host, $port, $path];
    }

    /**
     * Starts async generation request.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @param ChatGenerateOptions $options Generation options (model, temperature, etc.)
     * @return bool True if started, false if already busy or model missing
     */
    public function startGenerate(array $messages, ChatGenerateOptions $options): bool
    {
        if ($this->httpClient->isBusy()) {
            return false;
        }

        $model = $options->model ?? $this->defaultModel;
        if ($model === null || $model === '') {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_MODEL_REQUIRED_OPENAI);
            return false;
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

        if ($options->responseFormat !== null && $options->responseFormat !== []) {
            $payload[LLMApiConstants::KEY_RESPONSE_FORMAT] = $options->responseFormat;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_FAILED_ENCODE_PAYLOAD);
            return false;
        }

        $timeoutMs = (int) ceil($options->timeoutSec * 1000);
        $this->httpClient->timeout = (float) max($timeoutMs, 1000);
        $this->httpClient->setRequestOptions(
            HttpConstants::METHOD_POST,
            null,
            $body,
            [
                HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
        );

        $currentTimeMs = microtime(true) * 1000;

        return $this->httpClient->startNewRequest($currentTimeMs);
    }

    /**
     * Advances async client (call in event loop).
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    public function tick(float $currentTimeMs): void
    {
        $this->httpClient->tick($currentTimeMs);
    }

    /**
     * Checks if generation result is ready.
     *
     * @return bool True if getResult() will return content
     */
    public function hasResult(): bool
    {
        return $this->httpClient->hasResult();
    }

    /**
     * Returns generated content or null on error.
     *
     * @return ?string Assistant message or null if failed/invalid response
     */
    public function getResult(): ?string
    {
        $result = $this->httpClient->getResult();
        $success = $result[HttpConstants::RESPONSE_KEY_SUCCESS] ?? false;
        $body = $result[HttpConstants::RESPONSE_KEY_BODY] ?? null;

        if (!$success || $body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        $content = $decoded[LLMApiConstants::KEY_CHOICES][0][LLMApiConstants::KEY_MESSAGE][LLMApiConstants::KEY_CONTENT] ?? null;
        if (!is_string($content)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OPENAI_RESPONSE_INVALID . $this->truncateForLog($body)
            );
            return null;
        }

        return trim($content);
    }

    /**
     * Checks if request is in progress.
     *
     * @return bool True if busy
     */
    public function isBusy(): bool
    {
        return $this->httpClient->isBusy();
    }

    /**
     * Resets client state (cancels in-flight request).
     */
    public function reset(): void
    {
        $this->httpClient->reset();
    }

    /**
     * Truncates text for log output.
     *
     * @param string $text Text to truncate
     * @param int $limit Max length before truncation
     * @return string Original or truncated text with suffix
     */
    private function truncateForLog(string $text, int $limit = LLMApiConstants::TRUNCATE_LIMIT_DEFAULT): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . LLMApiConstants::TRUNCATE_SUFFIX;
    }
}
