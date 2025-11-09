<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Constants\WorkerConstants;
use Hilos\Exception\InvalidWorkerIdException;

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
     * Get worker index from command line arguments
     *
     * Parses --worker-id=N argument from $argv array.
     * Throws InvalidWorkerIdException if worker index is missing or invalid.
     *
     * @param array<string> $argv Command line arguments
     * @return int Worker index (positive integer)
     * @throws InvalidWorkerIdException If worker index is missing or not a positive integer
     */
    public static function getWorkerIndex(array $argv): int
    {
        $format = self::getWorkerIdArgFormat();
        $formatLength = self::getWorkerIdArgFormatLength();

        // Find worker ID argument
        foreach ($argv as $arg) {
            if (str_starts_with($arg, $format)) {
                $workerIndexString = substr($arg, $formatLength);

                // Check if value is empty
                if ($workerIndexString === '') {
                    throw new InvalidWorkerIdException('worker index value is empty');
                }

                // Parse as integer
                $workerIndex = (int)$workerIndexString;

                // Check if value was actually numeric (intval('abc') returns 0, but we need to check if original was numeric)
                if ((string)$workerIndex !== $workerIndexString) {
                    throw new InvalidWorkerIdException("worker index '{$workerIndexString}' is not a valid integer");
                }

                // Check if positive
                if ($workerIndex <= 0) {
                    throw new InvalidWorkerIdException("worker index must be positive, got {$workerIndex}");
                }

                return $workerIndex;
            }
        }

        // Worker ID argument not found
        throw new InvalidWorkerIdException('--worker-id argument is missing');
    }

    /**
     * Get worker index from command line arguments (alias for getWorkerIndex)
     *
     * @param array<string> $argv Command line arguments
     * @return int Worker index (positive integer)
     * @throws InvalidWorkerIdException If worker index is missing or not a positive integer
     * @deprecated Use getWorkerIndex() instead. This method is kept for backward compatibility.
     */
    public static function getWorkerId(array $argv): int
    {
        return self::getWorkerIndex($argv);
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
     * Build worker index argument string
     *
     * @param int $workerIndex Worker index
     * @return string Argument string (e.g., "--worker-id=1")
     */
    public static function buildWorkerIdArg(int $workerIndex): string
    {
        return self::getWorkerIdArgFormat() . $workerIndex;
    }

    /**
     * Build command line arguments array for worker process
     *
     * @param int $workerIndex Worker index
     * @param bool $monopolistic Whether worker is monopolistic
     * @return array<string> Array of command line arguments
     */
    public static function buildWorkerArgs(int $workerIndex, bool $monopolistic = false): array
    {
        $args = [self::buildWorkerIdArg($workerIndex)];

        if ($monopolistic) {
            $args[] = self::getMonopolisticArgFormat();
        }

        return $args;
    }
}
