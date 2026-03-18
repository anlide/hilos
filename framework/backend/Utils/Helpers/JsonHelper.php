<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * JsonHelper - JSON parsing utilities.
 *
 * Provides safe JSON decode helpers that return null on invalid input.
 *
 * @package Hilos\Utils\Helpers
 */
class JsonHelper
{
    /**
     * Extract first JSON object substring from text (e.g. LLM output).
     *
     * Handles trimmed whole-object strings and text with leading/trailing content.
     *
     * @param string $text Input text (may contain JSON)
     * @return ?string Extracted JSON string or null
     */
    public static function extractJsonObject(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            return $trimmed;
        }
        if (preg_match('/\{.*}/s', $trimmed, $matches) === 1) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Try to decode JSON payload into associative array.
     *
     * @param string $payload Raw JSON string
     * @return ?array<string, mixed> Decoded payload or null if invalid JSON
     */
    public static function tryDecode(string $payload): ?array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
