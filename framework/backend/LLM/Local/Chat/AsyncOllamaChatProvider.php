<?php

declare(strict_types=1);

namespace Hilos\LLM\Local\Chat;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Constants\TimeConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMClientBusyException;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Exception\LLMMessageContentMissingException;
use Hilos\LLM\Exception\LLMPayloadEncodeException;
use Hilos\LLM\Exception\LLMRequestException;
use Hilos\LLM\Exception\LLMResponseException;
use Hilos\LLM\Exception\LLMResultUnavailableException;
use Hilos\Socket\SocketException;

/**
 * AsyncOllamaChatProvider - Non-blocking local chat provider via Ollama API.
 *
 * Uses Ollama /api/generate (completion style) over AsyncHttpClient.
 * Call startGenerate(), then tick() in event loop until hasResult().
 *
 * A base URL with the https scheme is reached over TLS: a local model may stand behind a TLS
 * proxy, and the test stand's model is exactly that case (HIL-925).
 *
 * @implements AsyncChatLLMInterface
 */
class AsyncOllamaChatProvider implements AsyncChatLLMInterface
{
    private const string ENDPOINT = '/api/generate';

    /** Scheme of a base URL the model is reached at over TLS. */
    private const string SCHEME_TLS = 'https';

    /** Port of a plain base URL that names none: the one Ollama listens on. */
    private const int DEFAULT_PORT = 11434;

    /** Port of a TLS base URL that names none. */
    private const int DEFAULT_TLS_PORT = 443;

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
        [$host, $port, $path, $useTls] = $this->parseUrl($baseUrl);
        $this->httpClient = new AsyncHttpClient($host, $port, $path, useTls: $useTls);
        $this->defaultModel = $defaultModel;
    }

    /**
     * Parses base URL into host, port, path and whether the connection is encrypted.
     *
     * @param string $url Base URL of Ollama (e.g. http://127.0.0.1:11434)
     * @return array{0: string, 1: int, 2: string, 3: bool} [host, port, path, useTls]
     */
    private function parseUrl(string $url): array
    {
        $url = rtrim($url, '/');
        $parsed = parse_url($url);
        $useTls = ($parsed['scheme'] ?? null) === self::SCHEME_TLS;
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? ($useTls ? self::DEFAULT_TLS_PORT : self::DEFAULT_PORT);
        // external-boundary: parse_url reads a configured URL, which usually carries no path at all
        $path = ($parsed['path'] ?? '') ?: self::ENDPOINT;
        if ($path !== self::ENDPOINT && !str_ends_with($path, 'api/generate')) {
            $path = rtrim($path, '/') . self::ENDPOINT;
        }

        return [$host, $port, $path, $useTls];
    }

    /**
     * Starts non-blocking generation request.
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
            throw new LLMConfigurationException(LLMApiConstants::LOG_MODEL_REQUIRED_OLLAMA);
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
            throw new LLMPayloadEncodeException(
                LLMApiConstants::LOG_FAILED_ENCODE_PAYLOAD . ': ' . json_last_error_msg(),
            );
        }

        $timeoutMs = (int) ceil($options->timeoutSec * TimeConstants::MS_PER_SECOND);
        $this->httpClient->timeout = (float) max($timeoutMs, LLMConstants::MIN_REQUEST_TIMEOUT_MS);
        $this->httpClient->setRequestOptions(
            HttpConstants::METHOD_POST,
            null,
            $body,
            [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
        );

        $currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;

        try {
            $this->httpClient->startNewRequest($currentTimeMs);
        } catch (AsyncHttpException|SocketException $e) {
            throw new LLMRequestException('Failed to start Ollama generation request', $e);
        }
    }

    /**
     * Advances async HTTP client state. Call in event loop.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws LLMException When the active request fails
     */
    public function tick(float $currentTimeMs): void
    {
        try {
            $this->httpClient->tick($currentTimeMs);
        } catch (AsyncHttpException|SocketException $e) {
            throw new LLMRequestException('Ollama generation request failed', $e);
        }
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
     * Consumes generated text.
     *
     * @return string Response text
     * @throws LLMException When no result is available or response is invalid
     */
    public function consumeResult(): string
    {
        try {
            $body = $this->httpClient->consumeResult()->body;
        } catch (AsyncHttpException $e) {
            throw new LLMResultUnavailableException('No Ollama generation result is available', previous: $e);
        }

        if ($body === '') {
            throw new LLMResponseException('Ollama response body is empty');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_string($decoded[LLMApiConstants::KEY_RESPONSE] ?? null)) {
            throw new LLMResponseException(
                LLMApiConstants::LOG_OLLAMA_RESPONSE_INVALID . $this->truncateForLog($body),
            );
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
     * @throws LLMMessageContentMissingException When an array message carries no content
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
