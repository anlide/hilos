<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Exception\InvalidWorkerIdException;
use Hilos\Utils\Constants\WorkerConstants;

/**
 * ArgumentHelper - Command line argument parsing utilities
 *
 * Provides helper functions for parsing command line arguments.
 */
class ArgumentHelper
{
    /**
     * Get worker ID argument format string
     *
     * @return string Format string (e.g., "--worker-id=")
     */
    private static function getWorkerIdArgFormat(): string
    {
        return '--' . WorkerConstants::WORKER_ID_ARG . '=';
    }

    /**
     * Get length of worker ID argument format string
     *
     * @return int Format string length
     */
    private static function getWorkerIdArgFormatLength(): int
    {
        return strlen(self::getWorkerIdArgFormat());
    }

    /**
     * Get monopolistic argument format string
     *
     * @return string Format string (e.g., "--monopolistic")
     */
    private static function getMonopolisticArgFormat(): string
    {
        return '--' . WorkerConstants::MONOPOLISTIC_ARG;
    }

    /**
     * Get worker ID from command line arguments
     *
     * Parses --worker-id=N argument from $argv array.
     * Throws InvalidWorkerIdException if worker ID is missing or invalid.
     *
     * @param array<string> $argv Command line arguments
     * @return int Worker ID (positive integer)
     * @throws InvalidWorkerIdException If worker ID is missing or not a positive integer
     */
    public static function getWorkerId(array $argv): int
    {
        $format = self::getWorkerIdArgFormat();
        $formatLength = self::getWorkerIdArgFormatLength();

        // Find worker ID argument
        foreach ($argv as $arg) {
            if (str_starts_with($arg, $format)) {
                $workerIdString = substr($arg, $formatLength);
                
                // Check if value is empty
                if ($workerIdString === '') {
                    throw new InvalidWorkerIdException('worker ID value is empty');
                }
                
                // Parse as integer
                $workerId = (int)$workerIdString;
                
                // Check if value was actually numeric (intval('abc') returns 0, but we need to check if original was numeric)
                if ((string)$workerId !== $workerIdString) {
                    throw new InvalidWorkerIdException("worker ID '{$workerIdString}' is not a valid integer");
                }
                
                // Check if positive
                if ($workerId <= 0) {
                    throw new InvalidWorkerIdException("worker ID must be positive, got {$workerId}");
                }
                
                return $workerId;
            }
        }

        // Worker ID argument not found
        throw new InvalidWorkerIdException('--worker-id argument is missing');
    }

    /**
     * Check if monopolistic flag is present in arguments
     *
     * @param array<string> $argv Command line arguments
     * @return bool True if --monopolistic flag is present
     */
    public static function isMonopolistic(array $argv): bool
    {
        return in_array(self::getMonopolisticArgFormat(), $argv, true);
    }

    /**
     * Build worker ID argument string
     *
     * @param int $workerId Worker ID
     * @return string Argument string (e.g., "--worker-id=1")
     */
    public static function buildWorkerIdArg(int $workerId): string
    {
        return self::getWorkerIdArgFormat() . $workerId;
    }

    /**
     * Build command line arguments array for worker process
     *
     * @param int $workerId Worker ID
     * @param bool $monopolistic Whether worker is monopolistic
     * @return array<string> Array of command line arguments
     */
    public static function buildWorkerArgs(int $workerId, bool $monopolistic = false): array
    {
        $args = [self::buildWorkerIdArg($workerId)];

        if ($monopolistic) {
            $args[] = self::getMonopolisticArgFormat();
        }

        return $args;
    }
}

