<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Collection\AuthBlocks as ObjectAuthBlocks;
use Hilos\Database\Object\Collection\Files as ObjectFiles;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Collection\OAuthProviders as ObjectOAuthProviders;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Collection\SecondFactorBackupCodes as ObjectSecondFactorBackupCodes;
use Hilos\Database\Object\Collection\SecondFactorResets as ObjectSecondFactorResets;
use Hilos\Database\Object\Collection\SecondFactors as ObjectSecondFactors;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Collection\SecondFactorTrusts as ObjectSecondFactorTrusts;
use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Collection\StepUps as ObjectStepUps;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\AuthBlocks as DbCollectionAuthBlocks;
use Hilos\Database\View\Collection\Files as DbCollectionFiles;
use Hilos\Database\View\Collection\Identities as DbCollectionIdentities;
use Hilos\Database\View\Collection\NotificationDeliveries as DbCollectionNotificationDeliveries;
use Hilos\Database\View\Collection\NotificationPreferences as DbCollectionNotificationPreferences;
use Hilos\Database\View\Collection\Notifications as DbCollectionNotifications;
use Hilos\Database\View\Collection\OAuthProviders as DbCollectionOAuthProviders;
use Hilos\Database\View\Collection\PasskeyCredentials as DbCollectionPasskeyCredentials;
use Hilos\Database\View\Collection\PushSubscriptions as DbCollectionPushSubscriptions;
use Hilos\Database\View\Collection\RegistrationReservations as DbCollectionRegistrationReservations;
use Hilos\Database\View\Collection\SecondFactorBackupCodes as DbCollectionSecondFactorBackupCodes;
use Hilos\Database\View\Collection\SecondFactorResets as DbCollectionSecondFactorResets;
use Hilos\Database\View\Collection\SecondFactors as DbCollectionSecondFactors;
use Hilos\Database\View\Collection\SecondFactorSettings as DbCollectionSecondFactorSettings;
use Hilos\Database\View\Collection\SecondFactorTrusts as DbCollectionSecondFactorTrusts;
use Hilos\Database\View\Collection\Sessions as DbCollectionSessions;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Collection\StepUps as DbCollectionStepUps;
use Hilos\Database\View\Collection\UserVerifications as DbCollectionUserVerifications;
use Hilos\Database\View\Collection\VerifierCircleMembers as DbCollectionVerifierCircleMembers;
use Hilos\Database\Actions\Collection\FilesActions;
use Hilos\Database\Actions\Collection\NotificationPreferencesActions;
use Hilos\Database\Actions\Collection\NotificationsActions;
use Hilos\Database\Actions\Collection\OAuthProvidersActions;
use Hilos\Database\Actions\Collection\PushSubscriptionsActions;
use Hilos\Database\Actions\Collection\SecondFactorBackupCodesActions;
use Hilos\Database\Actions\Collection\SecondFactorResetsActions;
use Hilos\Database\Actions\Collection\SecondFactorsActions;
use Hilos\Database\Actions\Collection\SecondFactorSettingsActions;
use Hilos\Database\Actions\Collection\SecondFactorTrustsActions;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Collection\StepUpsActions;
use Hilos\Database\Actions\Collection\VerifierCircleMembersActions;
use Hilos\Database\Actions\Item\FileActions;
use Hilos\Database\Actions\Item\NotificationActions;
use Hilos\Database\Actions\Item\OAuthProviderActions;
use Hilos\Database\Actions\Item\SecondFactorActions;
use Hilos\Database\Actions\Item\SecondFactorBackupCodeActions;
use Hilos\Database\Actions\Item\SecondFactorResetActions;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Actions\Item\SettingActions;
use Hilos\Database\Actions\Item\VerifierCircleMemberActions;

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
 * @property-read DbCollectionRegistrationReservations $registrationReservations
 * @property-read DbCollectionPasskeyCredentials $passkeyCredentials
 * @property-read DbCollectionSessions $sessions
 * @property-read DbCollectionNotifications $notifications
 * @property-read DbCollectionNotificationDeliveries $notificationDeliveries
 * @property-read DbCollectionNotificationPreferences $notificationPreferences
 * @property-read DbCollectionPushSubscriptions $pushSubscriptions
 * @property-read DbCollectionVerifierCircleMembers $verifierCircle
 * @property-read DbCollectionAuthBlocks $authBlocks
 * @property-read DbCollectionOAuthProviders $oauthProviders
 * @property-read DbCollectionSecondFactors $secondFactors
 * @property-read DbCollectionSecondFactorBackupCodes $secondFactorBackupCodes
 * @property-read DbCollectionSecondFactorTrusts $secondFactorTrusts
 * @property-read DbCollectionSecondFactorResets $secondFactorResets
 * @property-read DbCollectionSecondFactorSettings $secondFactorSettings
 * @property-read DbCollectionStepUps $stepUps
 * @property-read DbCollectionFiles $files
 */
abstract class HilosDbContext extends DbContext
{
    public const string settings = 'settings';
    public const string setting = 'setting';
    public const string identities = 'identities';
    public const string identity = 'identity';
    public const string verifications = 'verifications';
    public const string verification = 'verification';
    public const string registrationReservations = 'registrationReservations';
    public const string registrationReservation = 'registrationReservation';
    public const string passkeyCredentials = 'passkeyCredentials';
    public const string passkeyCredential = 'passkeyCredential';
    public const string sessions = 'sessions';
    public const string session = 'session';
    public const string notifications = 'notifications';
    public const string notification = 'notification';
    public const string notificationDeliveries = 'notificationDeliveries';
    public const string notificationDelivery = 'notificationDelivery';
    public const string notificationPreferences = 'notificationPreferences';
    public const string notificationPreference = 'notificationPreference';
    public const string pushSubscriptions = 'pushSubscriptions';
    public const string pushSubscription = 'pushSubscription';
    public const string verifierCircle = 'verifierCircle';
    public const string authBlocks = 'authBlocks';
    public const string authBlock = 'authBlock';
    public const string oauthProviders = 'oauthProviders';
    public const string oauthProvider = 'oauthProvider';
    public const string secondFactors = 'secondFactors';
    public const string secondFactor = 'secondFactor';
    public const string secondFactorBackupCodes = 'secondFactorBackupCodes';
    public const string secondFactorBackupCode = 'secondFactorBackupCode';
    public const string secondFactorTrusts = 'secondFactorTrusts';
    public const string secondFactorTrust = 'secondFactorTrust';
    public const string secondFactorResets = 'secondFactorResets';
    public const string secondFactorReset = 'secondFactorReset';
    public const string secondFactorSettings = 'secondFactorSettings';
    public const string secondFactorSetting = 'secondFactorSetting';
    public const string stepUps = 'stepUps';
    public const string stepUp = 'stepUp';
    public const string files = 'files';
    public const string file = 'file';

    /**
     * Configures Hilos-level collections (settings, identities, verifications,
     * passkey credentials, sessions, notifications, notification deliveries,
     * notification preferences, push subscriptions, the verifier circle, auth blocks,
     * OAuth providers, the five tables of the second factor, operation confirmations, and the
     * files registry).
     *
     * Identities, verifications, passkey credentials, sessions, notifications,
     * notification deliveries, notification preferences, push subscriptions and auth
     * blocks load by key (per-user / per-(type,identifier) / per-credential / per-token /
     * per-recipient / per-(notification,channel) / per-(user,channel) / per-endpoint /
     * per-(scope,identity,action) lookups), never as a full set, so registering the
     * collections stays inert for projects that do not activate the hilos_identity /
     * hilos_user_verification / hilos_passkey_credential / hilos_session /
     * hilos_notification / hilos_notification_delivery / hilos_notification_preference /
     * hilos_push_subscription / hilos_auth_block tables.
     *
     * The second factor's five tables (HIL-494) - authenticators, backup codes, trusted
     * browsers, delayed removals and each person's own removal wait - load by key or by person
     * too, and stay inert for the same reason where nobody signs in.
     * Operation confirmations (HIL-495) load by their browser/person/operation tuple and
     * stay inert until a protected account command asks for one.
     *
     * OAuth providers are read whole on the first lookup of a provider in the process
     * (HIL-1080) - a row per declared provider, asked on every handshake - and stay inert
     * where nobody looks a provider up, so a project without hilos_oauth_provider never
     * reads it.
     *
     * The verifier circle is read whole, and it stays inert for a different reason: nothing
     * reads it but the photograph a freeze takes, so a node that never freezes never loads it.
     * Its table is in every installation that builds a runtime context - the one that can
     * freeze - because the project's own unit test refuses such a project without the
     * hilos_verifier_circle migration (HIL-1118).
     *
     * The files registry (HIL-336) loads by row id and by the files library's bounded batch of
     * unbound rows, never as a full set, so it stays inert for projects that do not activate the
     * hilos_file table.
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

        $this->_objectCollections[self::registrationReservations] = ObjectRegistrationReservations::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::registrationReservations, DbCollectionRegistrationReservations::class);

        $this->_objectCollections[self::passkeyCredentials] = ObjectPasskeyCredentials::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::passkeyCredentials, DbCollectionPasskeyCredentials::class);

        $this->_objectCollections[self::sessions] = ObjectSessions::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::sessions, DbCollectionSessions::class, SessionsActions::class, SessionActions::class);

        $this->_objectCollections[self::notifications] = ObjectNotifications::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::notifications, DbCollectionNotifications::class, NotificationsActions::class, NotificationActions::class);

        $this->_objectCollections[self::notificationDeliveries] = ObjectNotificationDeliveries::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::notificationDeliveries, DbCollectionNotificationDeliveries::class);

        $this->_objectCollections[self::notificationPreferences] = ObjectNotificationPreferences::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::notificationPreferences, DbCollectionNotificationPreferences::class, NotificationPreferencesActions::class);

        $this->_objectCollections[self::pushSubscriptions] = ObjectPushSubscriptions::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::pushSubscriptions, DbCollectionPushSubscriptions::class, PushSubscriptionsActions::class);

        $this->_objectCollections[self::verifierCircle] = ObjectVerifierCircleMembers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::verifierCircle,
            DbCollectionVerifierCircleMembers::class,
            VerifierCircleMembersActions::class,
            VerifierCircleMemberActions::class,
        );

        $this->_objectCollections[self::authBlocks] = ObjectAuthBlocks::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::authBlocks, DbCollectionAuthBlocks::class);

        $this->_objectCollections[self::oauthProviders] = ObjectOAuthProviders::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::oauthProviders,
            DbCollectionOAuthProviders::class,
            OAuthProvidersActions::class,
            OAuthProviderActions::class,
        );

        $this->_objectCollections[self::secondFactors] = ObjectSecondFactors::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::secondFactors,
            DbCollectionSecondFactors::class,
            SecondFactorsActions::class,
            SecondFactorActions::class,
        );

        $this->_objectCollections[self::secondFactorBackupCodes] = ObjectSecondFactorBackupCodes::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::secondFactorBackupCodes,
            DbCollectionSecondFactorBackupCodes::class,
            SecondFactorBackupCodesActions::class,
            SecondFactorBackupCodeActions::class,
        );

        $this->_objectCollections[self::secondFactorTrusts] = ObjectSecondFactorTrusts::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::secondFactorTrusts, DbCollectionSecondFactorTrusts::class, SecondFactorTrustsActions::class);

        $this->_objectCollections[self::secondFactorResets] = ObjectSecondFactorResets::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::secondFactorResets,
            DbCollectionSecondFactorResets::class,
            SecondFactorResetsActions::class,
            SecondFactorResetActions::class,
        );

        $this->_objectCollections[self::secondFactorSettings] = ObjectSecondFactorSettings::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(
            self::secondFactorSettings,
            DbCollectionSecondFactorSettings::class,
            SecondFactorSettingsActions::class,
        );

        $this->_objectCollections[self::stepUps] = ObjectStepUps::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::stepUps, DbCollectionStepUps::class, StepUpsActions::class);

        $this->_objectCollections[self::files] = ObjectFiles::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::files, DbCollectionFiles::class, FilesActions::class, FileActions::class);
    }

    /**
     * Names the framework collections read from any process at all.
     *
     * Six, and each for its own seam. Sessions and identities answer "whose session is this",
     * which {@see SessionCarrier} asks in every process a frame arrives in - outside any agent
     * and before any page subscription, so nothing else declares them. Settings is read by seams
     * everywhere and is the one eager collection of the three, so a worker holding it unaddressed
     * would go on serving the values it started with forever.
     *
     * Notifications is here for a different reason, and since HIL-860 it is no longer a page's:
     * the notification center has no page any more. The collection stays declared because its
     * readers are several and they live in different workers - the library agent that owns the
     * rows, the per-user group that answers a join with the snapshot, and the delivery-channel
     * agent that reads a row as it sends it. Which of them would stop seeing fresh rows without
     * this entry is a question this leaf does not answer.
     *
     * OAuth providers (HIL-286) are read wherever a provider registry is built: the users
     * library that starts a sign-in, the OAuth agent that finishes one, and the seam that
     * names a project's sign-in methods. What the administrator entered takes effect on the
     * next build in each of them, so each has to hold the rows as they are now: they hold
     * them in memory and receive their changes by synchronization.
     *
     * The verifier circle (HIL-1118) is read by the photograph a freeze takes, in the worker of
     * whichever agent asked for the freeze - and any agent may ask. Declaring it per initiator is
     * the silent trap of {@see AbstractAgent::READS_DB}, where a subclass list replaces the
     * parent's; declared by one agent, the read was refused in every other worker, the
     * initiator's own included (HIL-1096).
     *
     * Named rather than counted: what is here is what the framework is known to read that way,
     * and a seam this list forgets shows up as a refused read rather than as a stale row.
     *
     * @return list<string> Collection keys the framework reads process-wide
     */
    protected function processWideReadCollections(): array
    {
        return [
            ...parent::processWideReadCollections(),
            self::settings,
            self::identities,
            self::sessions,
            self::notifications,
            self::oauthProviders,
            self::verifierCircle,
        ];
    }
}
