<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

/**
 * Step-up method values carried on the wire (HIL-495).
 */
final class StepUpMethod
{
    public const string SECOND_FACTOR = 'second_factor';
    public const string PASSWORD = 'password';
    public const string EMAIL_CODE = 'email_code';
    public const string SMS_CODE = 'sms_code';
    public const string PASSKEY = 'passkey';
}
