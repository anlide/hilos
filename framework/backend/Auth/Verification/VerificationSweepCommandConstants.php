<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

/** Wire keys of the test-only verification sweep command (HIL-1163). */
final class VerificationSweepCommandConstants
{
    public const string FIELD_IDENTIFIER = 'identifier';
    public const string FIELD_REMOVED = 'removed';
    public const string FIELD_KEPT = 'kept';
}
