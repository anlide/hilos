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
 * AsyncOllamaChatProvider - Non-blocking local chat provider via Ollama API.
 *
 * Uses Ollama /api/generate (completion style) over AsyncHttpClient.
 * Call startGenerate(), then tick() in event loop until hasResult().
 *
 * @implements AsyncChatLLMInterface
 */
class AsyncOllamaChatProvider implements AsyncChatLLMInterface
{
    private const string ENDPOINT = '/api/generate';

    /** @var AsyncHttpClient Async HTTP client for Ollama API */
    private AsyncHttpClient $httpClient;

    /** @var ?string Default model name */
    private ?string $defaultModel;

    /**
     * Creates Ollama chat provider with base URL and optional default model.
     *
     * @param string $baseUrl Base URL (e.g. http://127.0.0.1:11434)
     * @param ?string $defaultModel Default model name (optional)
     */
    public function __construct(string $baseUrl, ?string $defaultModel = null)
    {
        [$host, $port, $path] = $this->parseUrl($baseUrl);
        $this->httpClient = new AsyncHttpClient($host, $port, $path);
        $this->defaultModel = $defaultModel;
    }

    /**
     * Parses base URL into host, port and path.
     *
     * @param string $url Base URL of Ollama (e.g. http://127.0.0.1:11434)
     * @return array{0: string, 1: int, 2: string} [host, port, path]
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

    /**
     * Starts non-blocking generation request.
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

        if ($options->responseFormat !== null && $options->responseFormat !== []) {
            $payload['format'] = 'json';
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

    /**
     * Advances async HTTP client state. Call in event loop.
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
     * @return bool True if result available
     */
    public function hasResult(): bool
    {
        return $this->httpClient->hasResult();
    }

    /**
     * Returns generated text or null on error/invalid response.
     *
     * @return ?string Response text or null
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
        if (!is_array($decoded) || !is_string($decoded[LLMApiConstants::KEY_RESPONSE] ?? null)) {
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID . $this->truncateForLog($body)
            );
            return null;
        }

        return trim($decoded[LLMApiConstants::KEY_RESPONSE]);
    }

    /**
     * Checks if HTTP request is in progress.
     *
     * @return bool True if busy
     */
    public function isBusy(): bool
    {
        return $this->httpClient->isBusy();
    }

    /**
     * Resets client state for new request.
     */
    public function reset(): void
    {
        $this->httpClient->reset();
    }

    /**
     * Converts message array to Ollama prompt string.
     *
     * @param list<Message|array{role: string, content: string}> $messages Chat messages
     * @return string Concatenated prompt for Ollama
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

    /**
     * Truncates text for logging.
     *
     * @param string $text Text to truncate
     * @param int $limit Max length (default from constants)
     * @return string Truncated string with suffix if exceeded
     */
    private function truncateForLog(string $text, int $limit = LLMApiConstants::TRUNCATE_LIMIT_DEFAULT): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . LLMApiConstants::TRUNCATE_SUFFIX;
    }
}
