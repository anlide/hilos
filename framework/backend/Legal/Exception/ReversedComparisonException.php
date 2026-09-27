<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

/** A comparison requires the earlier revision first (HIL-498). */
final class ReversedComparisonException extends LegalException
{
}
