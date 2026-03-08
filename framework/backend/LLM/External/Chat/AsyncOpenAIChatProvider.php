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

    public function __construct(string $baseUrl, string $apiKey, ?string $defaultModel = null)
    {
        [$host, $port, $path] = $this->parseUrl($baseUrl);
        $this->httpClient = new AsyncHttpClient($host, $port, $path, useTls: true);
        $this->defaultModel = $defaultModel;
        $this->apiKey = $apiKey;
    }

    private ?string $defaultModel;
    private string $apiKey;

    /**
     * @return array{0: string, 1: int, 2: string}
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

    public function tick(float $currentTimeMs): void
    {
        $this->httpClient->tick($currentTimeMs);
    }

    public function hasResult(): bool
    {
        return $this->httpClient->hasResult();
    }

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

    public function isBusy(): bool
    {
        return $this->httpClient->isBusy();
    }

    public function reset(): void
    {
        $this->httpClient->reset();
    }

    private function truncateForLog(string $text, int $limit = LLMApiConstants::TRUNCATE_LIMIT_DEFAULT): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . LLMApiConstants::TRUNCATE_SUFFIX;
    }
}
