<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use DateTimeImmutable;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Announces a delayed removal of a person's second factor on every channel (HIL-494).
 *
 * Four announcements ({@see SecondFactorNotificationType}), all mandatory, all at warning: the
 * removal asked for, the daily reminder, the cancel, and the removal carried out. The first
 * and every reminder carry the same "it was not me" link - in the body for mail and SMS, and
 * as `data.url`, which the push opens and the bell links to. No channel is chosen here: a
 * mandatory type goes to every enabled channel the person has an address on, whatever their
 * own preferences say.
 *
 * An installation with no notifier wired announces nothing; the removal still waits its time.
 */
final class SecondFactorResetNotifier
{
    /** Query parameter the cancel link carries its token in. */
    public const string TOKEN_PARAM = 'token';

    /** Key of the link in the notification data - the one push and bell read. */
    private const string DATA_URL = 'url';

    /** Date form a removal's moment is written in. */
    private const string DATE_FORMAT = 'Y-m-d H:i P (e)';

    /**
     * Announces a removal just asked for.
     *
     * @param int $userId Person whose second factor is to be removed
     * @param string $token Token of the cancel link
     * @param int $effectiveAtSec Moment the removal takes effect (Unix seconds)
     * @throws InvalidArgumentException When the recipient View is invalid or the emit signal cannot be queued
     * @throws EnvException When the cancel link or server text configuration cannot be read
     * @throws EnvInvalidValueException When the server text mode is invalid
     * @throws ItemNotFoundException When the required recipient no longer exists
     * @throws DatabaseException When loading the recipient fails
     * @throws LogicException When the users collection is not configured
     */
    public static function requested(int $userId, string $token, int $effectiveAtSec): void
    {
        if (Hilos::$notify === null) {
            return;
        }

        $url = self::cancelUrl($token);
        self::emit(
            $userId,
            SecondFactorNotificationType::RESET_REQUESTED,
            'Removal of two-step verification requested',
            'Someone asked to remove two-step verification from your account. It will be removed on '
                . new DateTimeImmutable('@' . $effectiveAtSec)
                    ->setTimezone(TimeHelper::serverTextTimeZone($userId))->format(self::DATE_FORMAT)
                . '. If this was not you, cancel it: ' . $url,
            $url,
        );
    }

    /**
     * Reminds that a removal still stands, repeating the cancel link of the first notice.
     *
     * @param int $userId Person whose second factor is to be removed
     * @param string $token Token of the cancel link
     * @param int $effectiveAtSec Moment the removal takes effect (Unix seconds)
     * @throws InvalidArgumentException When the recipient View is invalid or the emit signal cannot be queued
     * @throws EnvException When the cancel link or server text configuration cannot be read
     * @throws EnvInvalidValueException When the server text mode is invalid
     * @throws ItemNotFoundException When the required recipient no longer exists
     * @throws DatabaseException When loading the recipient fails
     * @throws LogicException When the users collection is not configured
     */
    public static function reminder(int $userId, string $token, int $effectiveAtSec): void
    {
        if (Hilos::$notify === null) {
            return;
        }

        $url = self::cancelUrl($token);
        self::emit(
            $userId,
            SecondFactorNotificationType::RESET_REMINDER,
            'Two-step verification will be removed',
            'Two-step verification on your account will be removed on '
                . new DateTimeImmutable('@' . $effectiveAtSec)
                    ->setTimezone(TimeHelper::serverTextTimeZone($userId))->format(self::DATE_FORMAT)
                . '. If this was not you, cancel it: ' . $url,
            $url,
        );
    }

    /**
     * Announces a removal canceled.
     *
     * @param int $userId Person whose second factor stays
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function canceled(int $userId): void
    {
        self::emit(
            $userId,
            SecondFactorNotificationType::RESET_CANCELED,
            'Removal of two-step verification canceled',
            'The request to remove two-step verification from your account was canceled. Nothing changes.',
            null,
        );
    }

    /**
     * Announces a removal carried out.
     *
     * @param int $userId Person whose second factor was removed
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function completed(int $userId): void
    {
        self::emit(
            $userId,
            SecondFactorNotificationType::RESET_COMPLETED,
            'Two-step verification removed',
            'Two-step verification was removed from your account, as requested. You can turn it on again in your profile.',
            null,
        );
    }

    /**
     * Builds the "it was not me" link of a removal.
     *
     * @param string $token Token of the cancel link
     * @return string Absolute link
     * @throws EnvException When the cancel link address cannot be read
     */
    public static function cancelUrl(string $token): string
    {
        return Hilos::$env[EnvConstants::HILOS_SECOND_FACTOR_CANCEL_URL]->string() . '?'
            . http_build_query([self::TOKEN_PARAM => $token]);
    }

    /**
     * Hands one announcement to the notifier.
     *
     * @param int $userId Recipient
     * @param string $type Notification type
     * @param string $title Title
     * @param string $body Body
     * @param ?string $url Link the push opens and the bell links to, or null for none
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    private static function emit(int $userId, string $type, string $title, string $body, ?string $url): void
    {
        Hilos::$notify?->emit(new NotificationDraft(
            userId: $userId,
            type: $type,
            title: $title,
            severity: NotificationSeverity::WARNING,
            body: $body,
            data: $url === null ? null : [self::DATA_URL => $url],
        ));
    }
}
