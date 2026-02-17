<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Constants\EnvConstants;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;

/**
 * Env - Environment variable manager
 *
 * Handles reading and managing environment variables from .env files and system environment.
 * Provides automatic fallback to .env.example for default values.
 */
class Env
{
    private static ?array $envCache = null;
    private static ?array $exampleCache = null;

    /**
     * Get environment variable value
     *
     * Priority order:
     * 1. Loaded .env file (if loaded)
     * 2. System environment (getenv)
     * 3. .env.example file (if exists)
     * 4. Throw exception if not found
     *
     * @param EnvConstants|string $name Environment variable name or enum constant
     * @param ?string $default Default value if not found
     * @return string Environment variable value
     * @throws MissingEnvironmentVariableException If a variable is not found anywhere
     */
    public static function get(EnvConstants|string $name, ?string $default = null): string
    {
        // Extract string value from enum or string
        $variableName = $name instanceof EnvConstants ? $name->name : $name;

        // Check loaded .env cache
        if (self::$envCache !== null && isset(self::$envCache[$variableName])) {
            return self::$envCache[$variableName];
        }

        // Check system environment
        $value = getenv($variableName);
        if ($value !== false) {
            return $value;
        }

        // Check .env.example as fallback
        $exampleValue = self::getFromExample($variableName);
        if ($exampleValue !== null) {
            return $exampleValue;
        }

        // Use default if provided
        if ($default !== null) {
            return $default;
        }

        // Throw exception if not found
        throw new MissingEnvironmentVariableException($variableName);
    }

    /**
     * Get environment variable as integer
     *
     * Priority order:
     * 1. Loaded .env file (if loaded)
     * 2. System environment (getenv)
     * 3. .env.example file (if exists)
     * 4. Throw exception if not found
     *
     * @param EnvConstants|string $name Environment variable name or enum constant
     * @param ?int $default Default value if not found
     * @return int Environment variable value as integer
     * @throws MissingEnvironmentVariableException If a variable is not found anywhere
     */
    public static function getInt(EnvConstants|string $name, ?int $default = null): int
    {
        // Extract string value from enum or string
        $variableName = $name instanceof EnvConstants ? $name->name : $name;

        // Check loaded .env cache
        if (self::$envCache !== null && isset(self::$envCache[$variableName])) {
            return (int) self::$envCache[$variableName];
        }

        // Check system environment
        $value = getenv($variableName);
        if ($value !== false) {
            return (int) $value;
        }

        // Check .env.example as fallback
        $exampleValue = self::getFromExample($variableName);
        if ($exampleValue !== null) {
            return (int) $exampleValue;
        }

        // Use default if provided
        if ($default !== null) {
            return $default;
        }

        // Throw exception if not found
        throw new MissingEnvironmentVariableException($variableName);
    }

    /**
     * Load environment variables from .env file
     *
     * @param string $envFilePath Path to .env file
     * @return bool True if loaded successfully, false otherwise
     */
    public static function load(string $envFilePath = '.env'): bool
    {
        if (!file_exists($envFilePath)) {
            return false;
        }

        self::$envCache = self::parseEnvFile($envFilePath);
        return true;
    }

    /**
     * Copy .env.example to .env if .env doesn't exist
     *
     * @param string $examplePath Path to .env.example file
     * @param string $envPath Path to .env file
     * @return bool True if copied, false if .env already exists or .env.example doesn't exist
     */
    public static function copyExampleToEnv(string $examplePath = '.env.example', string $envPath = '.env'): bool
    {
        // Don't overwrite existing .env file
        if (file_exists($envPath)) {
            return false;
        }

        // Check if .env.example exists
        if (!file_exists($examplePath)) {
            return false;
        }

        return copy($examplePath, $envPath);
    }

    /**
     * Initialize environment
     *
     * This should be called at the start of the application.
     * It will copy .env.example to .env if needed and load the .env file.
     *
     * @param ?string $rootPath Root path of the application (null = auto-detect from project root)
     */
    public static function init(?string $rootPath = null): void
    {
        // Auto-detect project root if not provided
        if ($rootPath === null) {
            // Starting from framework/src/Utils, go up 3 levels to project root
            $rootPath = dirname(realpath(__DIR__), 4);
        }

        $envPath = $rootPath . '/.env';
        $examplePath = $rootPath . '/.env.example';

        // Copy .env.example to .env if .env doesn't exist
        if (!file_exists($envPath)) {
            self::copyExampleToEnv($examplePath, $envPath);
        }

        // Load .env file if it exists
        self::load($envPath);
    }

    /**
     * Get value from .env.example file
     *
     * @param string $name Environment variable name
     * @return ?string Value from .env.example or null if not found
     */
    private static function getFromExample(string $name): ?string
    {
        if (self::$exampleCache === null) {
            $examplePath = '.env.example';
            if (file_exists($examplePath)) {
                self::$exampleCache = self::parseEnvFile($examplePath);
            } else {
                self::$exampleCache = [];
            }
        }

        return self::$exampleCache[$name] ?? null;
    }

    /**
     * Parse .env file into associative array
     *
     * @param string $filePath Path to .env file
     * @return array Associative array of environment variables
     */
    private static function parseEnvFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE format
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                $value = trim($value, '"\'');
                $value = trim($value, "'");

                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Clear caches (useful for reloading environment)
     *
     * Called when SIGHUP signal is received to reload environment variables
     * from .env file without restarting the application.
     */
    public static function clearCache(): void
    {
        self::$envCache = null;
        self::$exampleCache = null;
    }

    /**
     * Reload environment configuration
     *
     * Clears caches and reloads .env file with auto-detection of project root.
     */
    public static function reload(): void
    {
        self::clearCache();

        // Auto-detect project root
        $rootPath = dirname(realpath(__DIR__), 4);
        $envPath = $rootPath . '/.env';

        // Reload .env file if it exists
        if (file_exists($envPath)) {
            self::load($envPath);
        }
    }
}
