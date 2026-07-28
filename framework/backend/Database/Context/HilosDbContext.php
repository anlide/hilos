<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\Identities as DbCollectionIdentities;
use Hilos\Database\View\Collection\NotificationDeliveries as DbCollectionNotificationDeliveries;
use Hilos\Database\View\Collection\Notifications as DbCollectionNotifications;
use Hilos\Database\View\Collection\PasskeyCredentials as DbCollectionPasskeyCredentials;
use Hilos\Database\View\Collection\Sessions as DbCollectionSessions;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Collection\UserVerifications as DbCollectionUserVerifications;
use Hilos\Database\Actions\Collection\NotificationsActions;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Item\NotificationActions;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Actions\Item\SettingActions;

/**
 * HilosDbContext - Framework database context with Hilos-level collections.
 *
 * Extends DbContext to add framework-owned collections (settings, identities,
 * verifications). Projects extend this class and add their own collections in
 * configure(); calling parent::configure() gives them the framework-owned
 * collections.
 *
 * @property-read DbCollectionSettings $settings
 * @property-read DbCollectionIdentities $identities
 * @property-read DbCollectionUserVerifications $verifications
 * @property-read DbCollectionPasskeyCredentials $passkeyCredentials
 * @property-read DbCollectionSessions $sessions
 * @property-read DbCollectionNotifications $notifications
 * @property-read DbCollectionNotificationDeliveries $notificationDeliveries
 */
abstract class HilosDbContext extends DbContext
{
    public const string settings = 'settings';
    public const string setting = 'setting';
    public const string identities = 'identities';
    public const string identity = 'identity';
    public const string verifications = 'verifications';
    public const string verification = 'verification';
    public const string passkeyCredentials = 'passkeyCredentials';
    public const string passkeyCredential = 'passkeyCredential';
    public const string sessions = 'sessions';
    public const string session = 'session';
    public const string notifications = 'notifications';
    public const string notification = 'notification';
    public const string notificationDeliveries = 'notificationDeliveries';
    public const string notificationDelivery = 'notificationDelivery';

    /**
     * Configures Hilos-level collections (settings, identities, verifications,
     * passkey credentials, sessions, notifications, notification deliveries).
     *
     * Identities, verifications, passkey credentials, sessions, notifications and
     * notification deliveries load by key (per-user / per-(type,identifier) /
     * per-credential / per-token / per-recipient / per-(notification,channel)
     * lookups), never as a full set, so registering the collections stays inert for
     * projects that do not activate the hilos_identity / hilos_user_verification /
     * hilos_passkey_credential / hilos_session / hilos_notification /
     * hilos_notification_delivery tables.
     *
     * @throws ObjectCollectionNotFoundException When a framework object collection is missing
     */
    public function configure(): void
    {
        $this->_objectCollections[self::settings] = ObjectSettings::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->setRepresent(self::settings, DbCollectionSettings::class, SettingsActions::class, SettingActions::class);

        $this->_objectCollections[self::identities] = ObjectIdentities::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::identities, DbCollectionIdentities::class);

        $this->_objectCollections[self::verifications] = ObjectUserVerifications::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::verifications, DbCollectionUserVerifications::class);

        $this->_objectCollections[self::passkeyCredentials] = ObjectPasskeyCredentials::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::passkeyCredentials, DbCollectionPasskeyCredentials::class);

        $this->_objectCollections[self::sessions] = ObjectSessions::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::sessions, DbCollectionSessions::class, SessionsActions::class, SessionActions::class);

        $this->_objectCollections[self::notifications] = ObjectNotifications::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::notifications, DbCollectionNotifications::class, NotificationsActions::class, NotificationActions::class);

        $this->_objectCollections[self::notificationDeliveries] = ObjectNotificationDeliveries::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::notificationDeliveries, DbCollectionNotificationDeliveries::class);
    }
}
