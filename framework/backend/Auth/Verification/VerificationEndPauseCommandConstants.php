<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

/** Wire keys of the test-only resend pause command. */
final class VerificationEndPauseCommandConstants
{
    public const string FIELD_ADDRESS = 'address';
    public const string FIELD_SESSION_TOKEN = 'sessionToken';
    public const string FIELD_AGED = 'aged';
}
