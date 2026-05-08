<?php

declare(strict_types=1);

namespace Hilos\LLM\External\Chat;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Constants\HttpConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMClientBusyException;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Exception\LLMPayloadEncodeException;
use Hilos\LLM\Exception\LLMRequestException;
use Hilos\LLM\Exception\LLMResponseException;
use Hilos\LLM\Exception\LLMResultUnavailableException;
use Hilos\Socket\SocketException;

/**
 * AsyncOpenAIChatProvider - Non-blocking external chat provider via OpenAI-compatible API.
 *
 * Uses /v1/chat/completions over AsyncHttpClient with HTTPS.
 * Compatible with OpenAI, OpenRouter, Azure OpenAI, etc.
 *
 * @implements AsyncChatLLMInterface
 */
class AsyncOpenAIChatProvider implements AsyncChatLLMInterface
{
    /** @var AsyncHttpClient Async HTTP client for API requests */
    private AsyncHttpClient $httpClient;

    /** @var ?string Default model name */
    private ?string $defaultModel;

    /** @var string API key for Bearer auth */
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
     * @throws LLMException When request setup or start fails
     */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        if ($this->httpClient->isBusy()) {
            throw new LLMClientBusyException();
        }

        $model = $options->model ?? $this->defaultModel;
        if ($model === null || $model === '') {
            throw new LLMConfigurationException(LLMApiConstants::LOG_MODEL_REQUIRED_OPENAI);
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
            throw new LLMPayloadEncodeException(
                LLMApiConstants::LOG_FAILED_ENCODE_PAYLOAD . ': ' . json_last_error_msg(),
            );
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

        try {
            $this->httpClient->startNewRequest($currentTimeMs);
        } catch (AsyncHttpException|SocketException $e) {
            throw new LLMRequestException('Failed to start OpenAI-compatible generation request', $e);
        }
    }

    /**
     * Advances async client (call in event loop).
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws LLMException When the active request fails
     */
    public function tick(float $currentTimeMs): void
    {
        try {
            $this->httpClient->tick($currentTimeMs);
        } catch (AsyncHttpException|SocketException $e) {
            throw new LLMRequestException('OpenAI-compatible generation request failed', $e);
        }
    }

    /**
     * Checks if generation result is ready.
     *
     * @return bool True if consumeResult() will return content
     */
    public function hasResult(): bool
    {
        return $this->httpClient->hasResult();
    }

    /**
     * Consumes generated content.
     *
     * @return string Assistant message
     * @throws LLMException When no result is available or response is invalid
     */
    public function consumeResult(): string
    {
        try {
            $body = $this->httpClient->consumeResult()->body;
        } catch (AsyncHttpException $e) {
            throw new LLMResultUnavailableException('No OpenAI-compatible generation result is available', previous: $e);
        }

        if ($body === '') {
            throw new LLMResponseException('OpenAI-compatible response body is empty');
        }

        $decoded = json_decode($body, true);
        $content = $decoded[LLMApiConstants::KEY_CHOICES][0][LLMApiConstants::KEY_MESSAGE][LLMApiConstants::KEY_CONTENT] ?? null;
        if (!is_string($content)) {
            throw new LLMResponseException(
                LLMApiConstants::LOG_OPENAI_RESPONSE_INVALID . $this->truncateForLog($body),
            );
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
