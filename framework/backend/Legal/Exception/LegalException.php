<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\HilosException;

/**
 * Base exception for the legal document model (HIL-497).
 *
 * Every child is a programming error - a faulty catalog declaration, a missing text file shipped
 * with it, or a read naming a revision nobody declared. Nothing a person types reaches this
 * family, so none of it is a `ValidationException`.
 */
class LegalException extends HilosException
{
}
