<?php

declare(strict_types=1);

namespace Hilos\Database\Verification;

use Hilos\Auth\MagicLink\MagicLinkService;
use Hilos\Auth\Verification\VerificationService;

/**
 * VerificationType - fixed value set for the hilos_user_verification `type` column.
 *
 * Mirrors the SQL ENUM on the table. The closed set is fixed here at the
 * verification foundation (HIL-365) so per-flow leaves write into the existing
 * shape without touching the migration. `register_confirm` verifies a freshly
 * registered email identity (HIL-164); `password_reset` mints a new password on
 * an existing password identity; `email_change` mints the code to the NEW address
 * of the profile change-email flow (HIL-299), and `email_change_current` the code to
 * the address the account holds now, which the same flow asks for first. Both carry
 * the owning `user_id` and are checked by {@see VerificationService::verify()} /
 * {@see VerificationService::matchCode()}.
 * `sms_login` mints a one-time code for phone-identity sign-in (HIL-280) — its
 * `identifier` is a normalized E.164 phone rather than an email, and the code is
 * verified with {@see VerificationService::verifyCode()} (no owning user is known
 * at issue time, so the challenge carries a null `user_id`).
 * `magic_link` mints a long URL-safe token for passwordless email sign-in
 * (HIL-283) — the owning user is resolved from a verified email identity at
 * request time and carried on the challenge, so it verifies through the same
 * {@see VerificationService::verify()} as the email code types (its stored value
 * is a token rather than a numeric code — see {@see VerificationService::issue()}).
 * `sms_add` mints a one-time code for attaching an `sms` identity to a signed-in
 * user from the profile (HIL-403) — its `identifier` is a normalized E.164 phone,
 * and unlike `sms_login` the owning user IS known at issue time, so the challenge
 * carries that `user_id` and it verifies through {@see VerificationService::verify()}
 * (the handler asserts the resolved user matches the session user).
 * `email_add` mints a one-time code for adding a password to a signed-in user with
 * no verified email (HIL-406) — its `identifier` is the target email (lowercased),
 * and like `sms_add` the owning user IS known at issue time, so the challenge carries
 * that `user_id` and it verifies through {@see VerificationService::verify()} (the
 * handler asserts the resolved user matches the session user, then writes the
 * password identity on the now-proven email).
 * `step_up` and `step_up_sms` prove the person again immediately before a protected
 * operation (HIL-495). Both carry the acting user id; the first is delivered to the
 * account email and the second to its phone.
 * `account_deletion` and `account_deletion_sms` confirm a person's own request to delete
 * their account (HIL-302). Both carry the acting user id and go to the same address a
 * step-up would pick; they are types of their own so the letter says what is being asked.
 * `magic_link_code` is the companion of `magic_link` (HIL-606) — the six digits that
 * ride in the same letter as the link, for the person who reads the mail on one device
 * and stands on the sign-in screen on another. It is minted inside the SAME issue as
 * the link and therefore passes no send gate of its own (one ceremony, one cooldown,
 * one letter); its attempt ceiling IS its own, so guessing the code cannot spend the
 * link's budget and vice versa. Whichever half is answered first consumes the other
 * ({@see MagicLinkService::verifyCode()}), which is what the letter promises.
 * `identifier` is the target email (lowercased) for the email-based types.
 */
final class VerificationType
{
    public const string REGISTER_CONFIRM = 'register_confirm';
    public const string PASSWORD_RESET = 'password_reset';
    public const string EMAIL_CHANGE = 'email_change';
    public const string SMS_LOGIN = 'sms_login';
    public const string MAGIC_LINK = 'magic_link';
    public const string MAGIC_LINK_CODE = 'magic_link_code';
    public const string SMS_ADD = 'sms_add';
    public const string EMAIL_ADD = 'email_add';
    public const string EMAIL_CHANGE_CURRENT = 'email_change_current';
    public const string STEP_UP = 'step_up';
    public const string STEP_UP_SMS = 'step_up_sms';
    public const string ACCOUNT_DELETION = 'account_deletion';
    public const string ACCOUNT_DELETION_SMS = 'account_deletion_sms';

    /**
     * Returns the fixed set of verification type values in declaration order.
     *
     * @return list<string> Every known verification type
     */
    public static function values(): array
    {
        return [
            self::REGISTER_CONFIRM,
            self::PASSWORD_RESET,
            self::EMAIL_CHANGE,
            self::SMS_LOGIN,
            self::MAGIC_LINK,
            self::MAGIC_LINK_CODE,
            self::SMS_ADD,
            self::EMAIL_ADD,
            self::EMAIL_CHANGE_CURRENT,
            self::STEP_UP,
            self::STEP_UP_SMS,
            self::ACCOUNT_DELETION,
            self::ACCOUNT_DELETION_SMS,
        ];
    }

    /**
     * Whether codes of this type travel by SMS rather than email.
     *
     * The send limit asks because an SMS costs money per message, so the two
     * channels carry their own caps.
     *
     * @param string $type Candidate type value (case-sensitive, matches the SQL ENUM)
     * @return bool True for the SMS-delivered types
     */
    public static function isSms(string $type): bool
    {
        return $type === self::SMS_LOGIN
            || $type === self::SMS_ADD
            || $type === self::STEP_UP_SMS
            || $type === self::ACCOUNT_DELETION_SMS;
    }

    /**
     * Whether the given string is a known verification type.
     *
     * @param string $type Candidate type value (case-sensitive, matches the SQL ENUM)
     * @return bool True when the value is one of the fixed set
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::values(), true);
    }
}
