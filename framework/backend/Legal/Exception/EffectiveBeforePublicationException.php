<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

/** The effective date precedes publication (HIL-498). */
final class EffectiveBeforePublicationException extends LegalException
{
}
