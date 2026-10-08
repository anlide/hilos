<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;

/**
 * Announces the lock wrong app codes put on a person's second factor (HIL-1285).
 *
 * One announcement per lock, mandatory, at warning ({@see SecondFactorNotificationType::APP_CODES_LOCKED}):
 * how many codes were missed, until when app codes are refused, that backup codes still work,
 * and what to do if it was not the person. No channel is chosen here: a mandatory type goes to
 * every enabled channel the person has an address on, whatever their own preferences say. The
 * lifting of a lock is not announced.
 *
 * An installation with no notifier wired announces nothing; the lock holds all the same.
 */
final class SecondFactorLockNotifier
{
    /** Date form the end of a lock is written in. */
    private const string DATE_FORMAT = 'Y-m-d H:i';

    /** Title of the announcement. */
    private const string TITLE = 'Two-step verification codes locked';

    /** Body of the announcement: the misses and the end of the lock follow. */
    private const string BODY = 'Someone entered %d wrong codes from your authenticator app. App codes are not accepted until %s;'
        . ' backup codes still work. If this was not you, change your password and sign out your other sessions in your profile.';

    /**
     * Announces a lock just put.
     *
     * @param int $userId Person whose app codes are locked
     * @param int $misses Wrong app codes that put the lock
     * @param int $untilSec End of the lock (Unix seconds)
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function locked(int $userId, int $misses, int $untilSec): void
    {
        Hilos::$notify?->emit(new NotificationDraft(
            userId: $userId,
            type: SecondFactorNotificationType::APP_CODES_LOCKED,
            title: self::TITLE,
            severity: NotificationSeverity::WARNING,
            body: sprintf(self::BODY, $misses, date(self::DATE_FORMAT, $untilSec)),
            data: null,
        ));
    }
}
