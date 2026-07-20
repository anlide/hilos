<?php

declare(strict_types=1);

namespace Hilos\Database\Verification;

/**
 * VerificationType - fixed value set for the hilos_user_verification `type` column.
 *
 * Mirrors the SQL ENUM on the table. The closed set is fixed here at the
 * verification foundation (HIL-365) so per-flow leaves write into the existing
 * shape without touching the migration. `register_confirm` verifies a freshly
 * registered email identity (HIL-164); `password_reset` mints a new password on
 * an existing password identity; `email_change` is reserved (schema
 * forward-compat) — its flow lands as the Profile-cluster consumer HIL-298.
 * `sms_login` mints a one-time code for phone-identity sign-in (HIL-280) — its
 * `identifier` is a normalized E.164 phone rather than an email, and the code is
 * verified with {@see VerificationService::verifyCode()} (no owning user is known
 * at issue time, so the challenge carries a null `user_id`).
 * `identifier` is the target email (lowercased) for the email-based types.
 */
final class VerificationType
{
    public const string REGISTER_CONFIRM = 'register_confirm';
    public const string PASSWORD_RESET = 'password_reset';
    public const string EMAIL_CHANGE = 'email_change';
    public const string SMS_LOGIN = 'sms_login';
}
