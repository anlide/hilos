<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Actions\Collection\DbActions as CollectionDbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Collection\AccessLogEntries as DbCollectionAccessLogEntries;
use Hilos\Database\View\Collection\AccountDeletions as DbCollectionAccountDeletions;
use Hilos\Database\View\Collection\DataExports as DbCollectionDataExports;
use Hilos\Database\View\Collection\LegalAcceptances as DbCollectionLegalAcceptances;
use Hilos\Database\View\Collection\AuthBlocks as DbCollectionAuthBlocks;
use Hilos\Database\View\Collection\Files as DbCollectionFiles;
use Hilos\Database\View\Collection\FileVariants as DbCollectionFileVariants;
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
use Hilos\Database\View\Collection\UserMerges as DbCollectionUserMerges;
use Hilos\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Hilos\Database\View\Collection\Users as DbCollectionUsers;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AdminAudience;
use Hilos\Database\View\Collection\UserVerifications as DbCollectionUserVerifications;
use Hilos\Database\View\Collection\VerifierCircleMembers as DbCollectionVerifierCircleMembers;
use Hilos\Database\Actions\Collection\AccessLogEntriesActions;
use Hilos\Database\Actions\Collection\AccountDeletionsActions;
use Hilos\Database\Actions\Collection\DataExportsActions;
use Hilos\Database\Actions\Collection\LegalAcceptancesActions;
use Hilos\Database\Actions\Collection\FilesActions;
use Hilos\Database\Actions\Collection\FileVariantsActions;
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
use Hilos\Database\Actions\Collection\UserMergesActions;
use Hilos\Database\Actions\Collection\UserRenamesActions;
use Hilos\Database\Actions\Collection\UsersActions;
use Hilos\Database\Actions\Collection\VerifierCircleMembersActions;
use Hilos\Database\Actions\Item\AccountDeletionActions;
use Hilos\Database\Actions\Item\DataExportActions;
use Hilos\Database\Actions\Item\FileActions;
use Hilos\Database\Actions\Item\FileVariantActions;
use Hilos\Database\Actions\Item\NotificationActions;
use Hilos\Database\Actions\Item\OAuthProviderActions;
use Hilos\Database\Actions\Item\SecondFactorActions;
use Hilos\Database\Actions\Item\SecondFactorBackupCodeActions;
use Hilos\Database\Actions\Item\SecondFactorResetActions;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Actions\Item\SettingActions;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Actions\Item\UserRenameActions;
use Hilos\Database\Actions\Item\VerifierCircleMemberActions;

/**
 * HilosDbContext - Framework database context with Hilos-level collections.
 *
 * Extends DbContext to add framework-owned collections (settings, identities,
 * verifications). Projects extend this class and add their own collections in
 * configure(); calling parent::configure() gives them the framework-owned
 * collections.
 *
 * A project that extends a framework table mounts its own chain under the framework's
 * key through {@see self::frameworkExtensions()}, and narrows the @property-read of that
 * key in its OWN context, the way a demo declares its own keys; the tags here stay the
 * framework's, and stay true - the mounted class is a subclass of what they name.
 *
 * @property-read DbCollectionSettings $settings
 * @property-read DbCollectionIdentities $identities
 * @property-read DbCollectionUserVerifications $verifications
 * @property-read DbCollectionRegistrationReservations $registrationReservations
 * @property-read DbCollectionPasskeyCredentials $passkeyCredentials
 * @property-read DbCollectionSessions $sessions
 * @property-read DbCollectionUsers $users
 * @property-read DbCollectionUserRenames $userRenames
 * @property-read DbCollectionUserMerges $userMerges
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
 * @property-read DbCollectionAccessLogEntries $accessLogEntries Account access log
 * @property-read DbCollectionDataExports $dataExports Data export requests
 * @property-read DbCollectionAccountDeletions $accountDeletions
 * @property-read DbCollectionLegalAcceptances $legalAcceptances
 * @property-read DbCollectionFiles $files
 * @property-read DbCollectionFileVariants $fileVariants
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
    public const string users = 'users';
    public const string user = 'user';
    public const string userRenames = 'userRenames';
    public const string userRename = 'userRename';
    public const string userMerges = 'userMerges';
    public const string userMerge = 'userMerge';
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
    public const string accessLogEntries = 'accessLogEntries';
    public const string accessLogEntry = 'accessLogEntry';
    public const string accountDeletions = 'accountDeletions';
    public const string accountDeletion = 'accountDeletion';
    public const string legalAcceptances = 'legalAcceptances';
    public const string legalAcceptance = 'legalAcceptance';
    public const string dataExports = 'dataExports';
    public const string dataExport = 'dataExport';
    public const string files = 'files';
    public const string file = 'file';
    public const string fileVariants = 'fileVariants';
    public const string fileVariant = 'fileVariant';

    /**
     * The layer names a refusal of a framework extension calls the three declared classes by;
     * the start guard names the same layers by the same words, and reads them here.
     */
    public const string LAYER_COLLECTION = 'view collection';
    public const string LAYER_ACTIONS = 'collection actions';
    public const string LAYER_ITEM_ACTIONS = 'item actions';

    /**
     * What the project declared through frameworkExtensions(), read once at the start of
     * configure() and consulted by every framework mount.
     *
     * @var array<string, FrameworkExtension>
     */
    private array $declaredExtensions = [];

    /**
     * The framework's own chain under each key configure() mounted, in the same three-class
     * shape a project declares a replacement in. Its keys are what a declaration is judged
     * against, and the start guard reads it whole through frameworkRegistrations().
     *
     * @var array<string, FrameworkExtension>
     */
    private array $frameworkChains = [];

    /**
     * Configures Hilos-level collections (settings, identities, verifications,
     * passkey credentials, sessions, notifications, notification deliveries,
     * notification preferences, push subscriptions, the verifier circle, auth blocks,
     * OAuth providers, the five tables of the second factor, operation confirmations, account
     * deletion requests, legal acceptances, the files registry, people, and their renames).
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
     * Account deletion requests (HIL-302) load by person and by the erasure sweep's due
     * moment, and stay inert where nobody signs in.
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
     * hilos_file table. Its image copies load by original file and stay inert without their table too (HIL-141).
     *
     * People load by key when a session, page or library asks for a person. A question about
     * everyone uses DbCollectionUsers::listAll(); mounting users stays inert where nobody signs in.
     * Their rename journal (HIL-1195) loads by row id and by the renamed person, and stays inert
     * where nobody is renamed, so a project without hilos_user_rename never reads it. Their
     * merges (HIL-1199) load by the folded account, and "is this account merged" is asked
     * wherever "who is an administrator" is - see processWideReadCollections().
     *
     * A project's own chain over a framework table is mounted here too, under the framework's
     * key: the declarations of {@see self::frameworkExtensions()} are read once, and each key is
     * mounted with the chain that answers for it - the framework's, or the project's declared for
     * that key (inheritance.md, *Mounting Under The Framework Key*). A declaration the mount
     * cannot honor is refused before anything reads, so a node, a CLI and the project's own unit
     * test all fail at this one place.
     *
     * @throws FrameworkExtensionException When a declared framework extension names a key the framework does not
     *     mount, is not a FrameworkExtension, or does not extend the framework's chain of its key
     * @throws CollectionAlreadyMountedException When a key is represented twice
     * @throws ObjectCollectionNotFoundException When a framework object collection is missing
     * @throws UnknownLazyStrategyException When a collection is mounted under a strategy initDB() does not know
     */
    public function configure(): void
    {
        $declared = $this->frameworkExtensions();
        foreach ($declared as $key => $extension) {
            if (!$extension instanceof FrameworkExtension) {
                throw new FrameworkExtensionException("Framework extension for [{$key}] is not a FrameworkExtension");
            }
        }
        $this->declaredExtensions = $declared;

        $this->mountFramework(
            self::settings,
            Objects::LAZY_STRATEGY_NONE,
            DbCollectionSettings::class,
            SettingsActions::class,
            SettingActions::class,
        );
        $this->mountFramework(self::identities, Objects::LAZY_STRATEGY_KEY, DbCollectionIdentities::class);
        $this->mountFramework(self::verifications, Objects::LAZY_STRATEGY_KEY, DbCollectionUserVerifications::class);
        $this->mountFramework(
            self::registrationReservations,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionRegistrationReservations::class,
        );
        $this->mountFramework(
            self::passkeyCredentials,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionPasskeyCredentials::class,
        );
        $this->mountFramework(
            self::sessions,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSessions::class,
            SessionsActions::class,
            SessionActions::class,
        );
        $this->mountFramework(
            self::notifications,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionNotifications::class,
            NotificationsActions::class,
            NotificationActions::class,
        );
        $this->mountFramework(
            self::notificationDeliveries,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionNotificationDeliveries::class,
        );
        $this->mountFramework(
            self::notificationPreferences,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionNotificationPreferences::class,
            NotificationPreferencesActions::class,
        );
        $this->mountFramework(
            self::pushSubscriptions,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionPushSubscriptions::class,
            PushSubscriptionsActions::class,
        );
        $this->mountFramework(
            self::verifierCircle,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionVerifierCircleMembers::class,
            VerifierCircleMembersActions::class,
            VerifierCircleMemberActions::class,
        );
        $this->mountFramework(self::authBlocks, Objects::LAZY_STRATEGY_KEY, DbCollectionAuthBlocks::class);
        $this->mountFramework(
            self::oauthProviders,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionOAuthProviders::class,
            OAuthProvidersActions::class,
            OAuthProviderActions::class,
        );
        $this->mountFramework(
            self::secondFactors,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSecondFactors::class,
            SecondFactorsActions::class,
            SecondFactorActions::class,
        );
        $this->mountFramework(
            self::secondFactorBackupCodes,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSecondFactorBackupCodes::class,
            SecondFactorBackupCodesActions::class,
            SecondFactorBackupCodeActions::class,
        );
        $this->mountFramework(
            self::secondFactorTrusts,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSecondFactorTrusts::class,
            SecondFactorTrustsActions::class,
        );
        $this->mountFramework(
            self::secondFactorResets,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSecondFactorResets::class,
            SecondFactorResetsActions::class,
            SecondFactorResetActions::class,
        );
        $this->mountFramework(
            self::secondFactorSettings,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionSecondFactorSettings::class,
            SecondFactorSettingsActions::class,
        );
        $this->mountFramework(
            self::stepUps,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionStepUps::class,
            StepUpsActions::class,
        );
        $this->mountFramework(
            self::accessLogEntries,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionAccessLogEntries::class,
            AccessLogEntriesActions::class,
        );
        $this->mountFramework(
            self::legalAcceptances,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionLegalAcceptances::class,
            LegalAcceptancesActions::class,
        );
        $this->mountFramework(
            self::accountDeletions,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionAccountDeletions::class,
            AccountDeletionsActions::class,
            AccountDeletionActions::class,
        );
        $this->mountFramework(
            self::dataExports,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionDataExports::class,
            DataExportsActions::class,
            DataExportActions::class,
        );
        $this->mountFramework(
            self::files,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionFiles::class,
            FilesActions::class,
            FileActions::class,
        );
        $this->mountFramework(
            self::fileVariants,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionFileVariants::class,
            FileVariantsActions::class,
            FileVariantActions::class,
        );
        $this->mountFramework(
            self::users,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionUsers::class,
            UsersActions::class,
            UserActions::class,
        );
        $this->mountFramework(
            self::userRenames,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionUserRenames::class,
            UserRenamesActions::class,
            UserRenameActions::class,
        );
        $this->mountFramework(
            self::userMerges,
            Objects::LAZY_STRATEGY_KEY,
            DbCollectionUserMerges::class,
            UserMergesActions::class,
        );

        $unknown = array_keys(array_diff_key($this->declaredExtensions, $this->frameworkChains));
        if ($unknown !== []) {
            throw new FrameworkExtensionException(
                'Framework extension declared for [' . implode(', ', $unknown) . '], which the framework does not mount;'
                . ' a project extends a framework key here and mounts its own tables in configure()',
            );
        }
    }

    /**
     * The framework's own chain under each of its keys, in registration order.
     *
     * Read by {@see FrameworkExtensionGuard} to hold what is mounted under a key against what the
     * framework itself registers there: the view collection, and the two action layers where it
     * registers any. The answer does not depend on what a project declared - it is the record
     * configure() keeps of its own registrations as it mounts, whichever chain answered for the
     * key. Empty until configure() ran.
     *
     * @return array<string, FrameworkExtension> The framework's chain per framework key, in registration order
     */
    public function frameworkRegistrations(): array
    {
        return $this->frameworkChains;
    }

    /**
     * The project's chains over framework tables, keyed by the framework key each replaces.
     *
     * Overridden by a project the way {@see self::processWideReadCollections()} is, and composed
     * from the parent's answer for the same reason - a context between the framework and the
     * project may declare chains of its own:
     *
     *     protected function frameworkExtensions(): array
     *     {
     *         return [
     *             ...parent::frameworkExtensions(),
     *             self::verifierCircle => new FrameworkExtension(
     *                 NotedMembers::class,
     *                 NotedMembersActions::class,
     *                 NotedMemberActions::class,
     *             ),
     *         ];
     *     }
     *
     * The framework declares nothing here: every key of its own is its own chain.
     *
     * @return array<string, FrameworkExtension> Project chain per framework key, empty when the project extends none
     */
    protected function frameworkExtensions(): array
    {
        return [];
    }

    /**
     * Names the framework collections read from any process at all.
     *
     * Ten, and each for its own seam. Sessions and identities answer "whose session is this",
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
     * People are read wherever the framework asks who a person is, and it asks in more places
     * than any one list of readers holds. The ADMIN gate ({@see BrowserContext::isAdmin()})
     * answers in whatever worker serves a gated page, a page that subscribes to nothing of its
     * own included (HIL-750); the account block is read by each guard where it stands - the
     * sign-in, the handshake, the block change, the copy handed to a blocked person; the
     * handshake's identity ({@see AbstractAgent::handshakeIdentity()}) is built in the agent that
     * holds the sockets; and the administrators' circle ({@see AdminAudience}) is asked by whatever
     * has to reach them. The collection loads by key, so the entry stays inert where nobody signs in.
     * The merges of people (HIL-1199) are read beside them for the same reason: a folded account
     * is refused rights and a block, and left out of the administrators' circle, wherever those
     * are asked. They load by the folded account and stay inert where nobody was ever merged.
     *
     * Deletion requests and acceptance records (HIL-945) are read by the freeze guard, which stands
     * in whatever worker serves a page or runs an action a signed-in person asks for, and composes
     * the person's standing out of them ({@see AccountStandingResolver}). The same process keeps
     * that standing in memory, and a write to either table is what drops it there: undeclared, the
     * write would reach only the worker that made it, and the guard elsewhere would go on judging
     * by the request or the acceptance that was there before.
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
            self::users,
            self::userMerges,
            self::accountDeletions,
            self::legalAcceptances,
        ];
    }

    /**
     * Mounts one framework key with the chain that answers for it: the framework's own, or the
     * project's declared for the key through frameworkExtensions().
     *
     * The object collection is the one the view collection names in OBJECT_COLLECTION_CLASS, for
     * the framework's chain exactly as for a project's: the view is the one place the chain is
     * named from, so there is no second list to fall behind. The loading strategy is the
     * framework's whichever chain is mounted. A project's declaration replaces the view and the
     * action layers it names; an action layer it leaves out stays the framework's, which the
     * start guard refuses as a half-inherited chain rather than this mount.
     *
     * @param string $key Framework collection key
     * @param int $strategy Loading strategy the framework reads the key by
     * @param class-string<DbCollection> $collection The framework's view collection for the key
     * @param ?class-string<CollectionDbActions> $actions The framework's collection actions, when it registers any
     * @param ?class-string<ItemDbActions> $itemActions The framework's item actions, when it registers any
     * @throws FrameworkExtensionException When the project's declaration for the key does not extend the framework's chain
     * @throws CollectionAlreadyMountedException When a view is already mounted under the key
     * @throws ObjectCollectionNotFoundException When the object collection is not there for the view to wrap
     * @throws UnknownLazyStrategyException When the strategy is none initDB() knows
     */
    private function mountFramework(
        string $key,
        int $strategy,
        string $collection,
        ?string $actions = null,
        ?string $itemActions = null,
    ): void {
        $this->frameworkChains[$key] = new FrameworkExtension($collection, $actions, $itemActions);

        $extension = $this->declaredExtensions[$key] ?? null;
        if ($extension !== null) {
            $this->assertExtends($key, self::LAYER_COLLECTION, $extension->collection, $collection);
            $actions = $this->extendedActions($key, self::LAYER_ACTIONS, $extension->actions, $actions);
            $itemActions = $this->extendedActions($key, self::LAYER_ITEM_ACTIONS, $extension->itemActions, $itemActions);
            $collection = $extension->collection;
        }

        $objectCollectionClass = $collection::OBJECT_COLLECTION_CLASS;
        $this->_objectCollections[$key] = $objectCollectionClass::initDB($strategy);
        $this->setRepresent($key, $collection, $actions, $itemActions);
    }

    /**
     * The action layer a mount ends up with: the project's, once it is seen to extend the
     * framework's, or the framework's where the project declared none.
     *
     * @param string $key Framework collection key
     * @param string $layer Name of the layer, for the refusal
     * @param ?string $declared The project's class for the layer, or null when it declared none
     * @param ?string $base The framework's class for the layer, or null when the framework registers none
     * @return ?string Class to mount for the layer
     * @throws FrameworkExtensionException When the layer is declared where the framework registers none, or does
     *     not extend the framework's
     */
    private function extendedActions(string $key, string $layer, ?string $declared, ?string $base): ?string
    {
        if ($declared === null) {
            return $base;
        }

        if ($base === null) {
            throw new FrameworkExtensionException(
                "Framework extension for [{$key}] declares {$layer}, which the framework does not register under"
                . ' that key; a subclass does not invent an action layer the base never had',
            );
        }

        $this->assertExtends($key, $layer, $declared, $base);

        return $declared;
    }

    /**
     * @param string $key Framework collection key
     * @param string $layer Name of the layer, for the refusal
     * @param string $class The project's class for the layer
     * @param string $base The framework's class for the layer
     * @throws FrameworkExtensionException When the class is not a subclass of the base - the base itself included
     */
    private function assertExtends(string $key, string $layer, string $class, string $base): void
    {
        if (!is_subclass_of($class, $base)) {
            throw new FrameworkExtensionException(
                "Framework extension for [{$key}] names {$class} as its {$layer},"
                . " which does not extend the framework's {$base}",
            );
        }
    }
}
