<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

/**
 * Thrown when a requested environment key is not declared in the catalog.
 */
final class EnvNotInCatalogException extends EnvException
{
}
