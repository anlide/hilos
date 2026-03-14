<?php

declare(strict_types=1);

namespace Hilos\LLM\External;

use Hilos\Constants\HttpConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\Utils\Logger;

/**
 * AbstractExternalProvider - Base for external LLM providers (OpenAI, OpenRouter, etc.).
 *
 * Common logic: API key authentication, HTTPS, shared HTTP helper.
 *
 * @property-read string $baseUrl Base URL (e.g. https://api.openai.com)
 * @property-read string $apiKey API key for authentication
 * @property-read ?string $defaultModel Default model name
 */
abstract class AbstractExternalProvider
{
    /** @var string Base URL of external LLM API */
    protected string $baseUrl;

    /** @var string API key for Bearer authentication */
    protected string $apiKey;

    /** @var ?string Default model name for requests */
    protected ?string $defaultModel;

    /**
     * Creates external LLM provider.
     *
     * @param string $baseUrl Base URL of external LLM API (without trailing slash)
     * @param string $apiKey API key for Bearer authentication
     * @param ?string $defaultModel Default model name for requests
     */
    public function __construct(string $baseUrl, string $apiKey, ?string $defaultModel = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel;
    }

    /**
     * Send JSON POST request with Bearer authentication.
     *
     * @param string $url Full endpoint URL
     * @param array<string, mixed> $payload Request payload
     * @param float $timeoutSec Request timeout in seconds
     * @return ?string Response body, or null on transport errors
     */
    protected function postJson(string $url, array $payload, float $timeoutSec): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_FAILED_ENCODE_PAYLOAD);
            return null;
        }

        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE . ': ' . HttpConstants::CONTENT_TYPE_JSON,
            LLMApiConstants::AUTH_BEARER_PREFIX . $this->apiKey,
        ];

        $context = stream_context_create([
            HttpConstants::STREAM_CONTEXT_HTTP => [
                HttpConstants::STREAM_OPT_METHOD => HttpConstants::METHOD_POST,
                HttpConstants::STREAM_OPT_HEADER => implode(HttpConstants::HTTP_LINE_SEPARATOR, $headers) . HttpConstants::HTTP_LINE_SEPARATOR,
                HttpConstants::STREAM_OPT_CONTENT => $body,
                HttpConstants::STREAM_OPT_TIMEOUT => $timeoutSec,
                HttpConstants::STREAM_OPT_IGNORE_ERRORS => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || $response === '') {
            $error = error_get_last();
            Logger::logAgentError(
                static::class,
                LLMApiConstants::LOG_EXTERNAL_LLM_UNAVAILABLE . ($error['message'] ?? LLMApiConstants::LOG_UNKNOWN)
            );
            return null;
        }

        return $response;
    }

    /**
     * Build full URL from path.
     *
     * @param string $path Path segment (e.g. v1/chat/completions)
     * @return string Full URL
     */
    protected function buildUrl(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->baseUrl . '/' . $path;
    }

    /**
     * Truncate long values for logging.
     *
     * @param string $text Text to truncate
     * @param int $limit Max length (default from LLMApiConstants::TRUNCATE_LIMIT_DEFAULT)
     * @return string Original text or truncated with suffix
     */
    protected function truncateForLog(string $text, int $limit = LLMApiConstants::TRUNCATE_LIMIT_DEFAULT): string
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . LLMApiConstants::TRUNCATE_SUFFIX;
    }
}
