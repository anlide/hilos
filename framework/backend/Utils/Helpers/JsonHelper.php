<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * JsonHelper - JSON parsing utilities
 *
 * Provides safe JSON decode helpers that return null on invalid input.
 *
 * @package Hilos\Utils\Helpers
 */
class JsonHelper
{
    /**
     * Try to decode JSON payload into associative array.
     *
     * @param string $payload Raw JSON string
     * @return ?array<string,mixed> Decoded payload or null if invalid JSON
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
