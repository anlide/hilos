<?php

declare(strict_types=1);

namespace Hilos\Users;

/** Caller-facing words for the moderation verdict on a profile name. */
final class ProfileNameRefusal
{
    public const string REASON_INSULT = 'insult';
    public const string REASON_THREAT = 'threat';
    public const string REASON_HATE_SPEECH = 'hate_speech';
    public const string REASON_SEXUAL = 'sexual';
    public const string REASON_SPAM = 'spam';
    public const string REASON_IMPERSONATION = 'impersonation';

    /**
     * @param string $reason Reason code of the moderation verdict
     * @return string Safe sentence; an unrecognized reason means no verdict was available
     */
    public static function forReason(string $reason): string
    {
        return match ($reason) {
            self::REASON_INSULT => 'This name was not accepted because it appears to contain an insult.',
            self::REASON_THREAT => 'This name was not accepted because it appears to contain a threat.',
            self::REASON_HATE_SPEECH => 'This name was not accepted because it appears to contain hate speech.',
            self::REASON_SEXUAL => 'This name was not accepted because it appears to contain sexual content.',
            self::REASON_SPAM => 'This name was not accepted because it appears to be spam.',
            self::REASON_IMPERSONATION => 'This name was not accepted because it appears to impersonate someone else.',
            default => 'Names cannot be checked right now.',
        };
    }

    /**
     * @param string $reason Reason code of the moderation verdict
     * @return bool Whether the name was refused for its content and merits a notification
     */
    public static function isAboutTheName(string $reason): bool
    {
        return in_array($reason, [
            self::REASON_INSULT,
            self::REASON_THREAT,
            self::REASON_HATE_SPEECH,
            self::REASON_SEXUAL,
            self::REASON_SPAM,
            self::REASON_IMPERSONATION,
        ], true);
    }
}
