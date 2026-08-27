<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

use Hilos\Hilos;

/**
 * Exception thrown when a process refuses to start with required environment values missing.
 *
 * Carries the whole list rather than the first name, which is the reason the startup check
 * exists at all: reading a value only when the code reaches it names one variable per launch,
 * so an operator filling a fresh installation learns the set one restart at a time. Names
 * only - no types and no hints - because the catalog and .env.example already carry those,
 * and a longer line is harder to scan in `docker logs` where this is read.
 */
final class MissingRequiredEnvironmentException extends EnvException
{
    /**
     * Creates a readable startup refusal from the collected missing names.
     *
     * @param class-string<Hilos> $hilosClass Facade class whose catalog was checked
     * @param list<string> $names Missing required environment variable names, in catalog order
     * @return self Exception instance
     */
    public static function forNames(string $hilosClass, array $names): self
    {
        return new self("{$hilosClass} refuses to start, required environment values are missing:\n- " . implode("\n- ", $names));
    }
}
