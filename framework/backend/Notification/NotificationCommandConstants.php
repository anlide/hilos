<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Notification\Delivery\NotificationDispatcher;

/**
 * NotificationCommandConstants - the wire vocabulary of the notification command channel.
 *
 * The CLI side builds the request payload and the agent side reads it, so both name the
 * keys from here and can never drift apart. Only the test-only emit command uses it today
 * ({@see CliCommands::NOTIFICATION_TEST_EMIT}, handled by {@see AbstractHilosIndexAgent}).
 *
 * The request fields mirror {@see NotificationDraft}; the reply reports what actually
 * landed in the database, which is the point of the command - the notification id and the
 * channels that got a delivery row from the {@see NotificationDispatcher}.
 */
final class NotificationCommandConstants
{
    /** @var string Request key: recipient user id */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Request key: machine notification type */
    public const string FIELD_TYPE = 'type';

    /** @var string Request key: rendered title */
    public const string FIELD_TITLE = 'title';

    /** @var string Request key: rendered body, absent when the draft carries none */
    public const string FIELD_BODY = 'body';

    /** @var string Request key: severity level (see NotificationSeverity) */
    public const string FIELD_SEVERITY = 'severity';

    /** @var string Request key: channel narrowing, absent when every enabled channel may deliver */
    public const string FIELD_CHANNELS = 'channels';

    /** @var string Reply key: id of the persisted notification */
    public const string FIELD_NOTIFICATION_ID = 'notificationId';

    /** @var string Reply key: channels that received a delivery row, read back from the table */
    public const string FIELD_QUEUED_CHANNELS = 'queuedChannels';
}
