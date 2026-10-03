<?php

declare(strict_types=1);

namespace Hilos\Users;

/** Caller-facing words for a project's profile photo verdict. */
final class ProfilePhotoRefusal
{
    public const string REASON_NUDITY = 'nudity';
    public const string REASON_VIOLENCE = 'violence';
    public const string REASON_HATE = 'hate';
    public const string REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    public const string REASON_UNKNOWN = 'unknown';

    public const string PHOTOS_NOT_KEPT = 'Profile photos are not kept here';
    public const string STILL_CHECKING = 'Your photo is still being checked';
    public const string NOTIFICATION_TITLE = 'Your new photo was not accepted';
    public const string PROFILE_PATH = '/profile';

    /**
     * @param string $reason Project checker reason
     * @return string Sentence to show the person
     */
    public static function forReason(string $reason): string
    {
        return match ($reason) {
            self::REASON_NUDITY => 'This photo was not accepted: it looks like nudity or sexual content.',
            self::REASON_VIOLENCE => 'This photo was not accepted: it looks violent.',
            self::REASON_HATE => 'This photo was not accepted: it looks like hate imagery.',
            self::REASON_SERVICE_UNAVAILABLE, self::REASON_UNKNOWN => 'Photos cannot be checked right now.',
            default => 'This photo was not accepted.',
        };
    }

    /**
     * @param string $reason Project checker reason
     * @return bool Whether refusal concerns the photo itself rather than the checker
     */
    public static function isAboutThePhoto(string $reason): bool
    {
        return !in_array($reason, [self::REASON_SERVICE_UNAVAILABLE, self::REASON_UNKNOWN], true);
    }
}
