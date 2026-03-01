<?php

declare(strict_types=1);

namespace Hilos\LLM\Local\Chat;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\HttpConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Logger;

/**
 * AsyncOllamaChatProvider - Non-blocking local chat provider via Ollama API
 *
 * Uses Ollama /api/generate (completion style) over AsyncHttpClient.
 * Call startGenerate(), then tick() in event loop until hasResult().
 *
 * @implements AsyncChatLLMInterface
 */
class AsyncOllamaChatProvider implements AsyncChatLLMInterface
{
    private const string ENDPOINT = '/api/generate';

    private AsyncHttpClient $httpClient;
    private ?string $defaultModel;

    public function __construct(string $baseUrl, ?string $defaultModel = null)
    {
        [$host, $port, $path] = $this->parseUrl($baseUrl);
        $this->httpClient = new AsyncHttpClient($host, $port, $path);
        $this->defaultModel = $defaultModel;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function parseUrl(string $url): array
    {
        $url = rtrim($url, '/');
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 11434;
        $path = ($parsed['path'] ?? '') ?: self::ENDPOINT;
        if ($path !== self::ENDPOINT && !str_ends_with($path, 'api/generate')) {
            $path = rtrim($path, '/') . self::ENDPOINT;
        }

        return [$host, $port, $path];
    }

    public function startGenerate(array $messages, ChatGenerateOptions $options): bool
    {
        if ($this->httpClient->isBusy()) {
            return false;
        }

        $model = $options->model ?? $this->defaultModel;
        if ($model === null || $model === '') {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_MODEL_REQUIRED_OLLAMA);
            return false;
        }

        $prompt = $this->messagesToPrompt($messages);
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
            [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
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
        if (!is_array($decoded) || !is_string($decoded[LLMApiConstants::KEY_RESPONSE] ?? null)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID . $this->truncateForLog($body)
            );
            return null;
        }

        return trim($decoded[LLMApiConstants::KEY_RESPONSE]);
    }

    public function isBusy(): bool
    {
        return $this->httpClient->isBusy();
    }

    public function reset(): void
    {
        $this->httpClient->reset();
    }

    /**
     * @param list<Message|array{role: string, content: string}> $messages
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

    private function truncateForLog(string $text, int $limit = LLMApiConstants::TRUNCATE_LIMIT_DEFAULT): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . LLMApiConstants::TRUNCATE_SUFFIX;
    }
}
