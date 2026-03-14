<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Exception;

use Hilos\HilosException;

/**
 * Base exception for CLI fixer tools (DbItemFixer, ItemCollectionFixer, etc.).
 */
class FixerException extends HilosException
{
}
