<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

/** An editorial revision must take effect on its publication date (HIL-498). */
final class EditorialRevisionDeferredException extends LegalException
{
}
