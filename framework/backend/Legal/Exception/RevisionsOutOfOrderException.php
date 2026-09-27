<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

/** Publication dates must follow declaration order (HIL-498). */
final class RevisionsOutOfOrderException extends LegalException
{
}
