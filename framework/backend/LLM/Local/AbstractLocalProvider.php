<?php

declare(strict_types=1);

namespace Hilos\LLM\Local;

use Hilos\Constants\HttpConstants;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\Utils\Logger;

/**
 * AbstractLocalProvider - Base for local LLM providers (Ollama, etc.)
 *
 * Common logic: no API key, local URL, shared HTTP helper.
 *
 * @property-read string $baseUrl Base URL (e.g. http://127.0.0.1:11434)
 * @property-read ?string $defaultModel Default model name
 */
abstract class AbstractLocalProvider
{
    protected string $baseUrl;
    protected ?string $defaultModel;

    /**
     * Creates local LLM provider with base URL and optional default model.
     *
     * @param string $baseUrl Base URL of local LLM service (without trailing slash)
     * @param ?string $defaultModel Default model name for requests
     */
    public function __construct(string $baseUrl, ?string $defaultModel = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultModel = $defaultModel;
    }

    /**
     * Send JSON POST request (no authentication).
     *
     * @param string $url Full endpoint URL
     * @param array<string, mixed> $payload Request payload
     * @param float $timeoutSec Request timeout in seconds
     *
     * @return ?string Response body, or null on transport errors
     */
    protected function postJson(string $url, array $payload, float $timeoutSec): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            Logger::logAgentError(static::class, LLMApiConstants::LOG_FAILED_ENCODE_PAYLOAD);
            return null;
        }

        $context = stream_context_create([
            HttpConstants::STREAM_CONTEXT_HTTP => [
                HttpConstants::STREAM_OPT_METHOD => HttpConstants::METHOD_POST,
                HttpConstants::STREAM_OPT_HEADER => HttpConstants::HEADER_CONTENT_TYPE . ': ' . HttpConstants::CONTENT_TYPE_JSON . HttpConstants::HTTP_LINE_SEPARATOR,
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
                LLMApiConstants::LOG_LOCAL_LLM_UNAVAILABLE . ($error['message'] ?? LLMApiConstants::LOG_UNKNOWN)
            );
            return null;
        }

        return $response;
    }

    /**
     * Build full URL from path.
     *
     * @param string $path Path segment (e.g. api/generate)
     * @return string Full URL (e.g. http://127.0.0.1:11434/api/generate)
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
