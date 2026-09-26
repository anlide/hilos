<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpeningReplyDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStateSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Code\DTO\CodeSendProgressSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationCanceledSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationProvenSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitHeldSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorCancelSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorMissedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorOffSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorSetupProvenSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\SecondFactor\DTO\SecondFactorPolicySignalData;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Auth\Session\DTO\ImpersonateRequestSignalData;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\DTO\SessionToastsSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupReopenSignalData;
use Hilos\Backup\Agent\DTO\DeferredNoticesSentSignalData;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Settings\Library\DTO\SettingDeleteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingPresetApplySignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogStoreAgent;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Notification\DTO\DeferredNotificationHandoverSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\DTO\NotificationForgetUserSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\AccountMergeSignalData;

/**
 * Signal names used by framework-level Hilos admin pages.
 */
final class HilosSignalConstants
{
    /** Subscription signal for Hilos dashboard page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DASHBOARD = 'subscription_page_hilos';

    /** Subscription signal for Hilos current-user profile page. */
    public const string SUBSCRIPTION_PAGE_HILOS_PROFILE = 'subscription_page_hilos_profile';

    public const string SUBSCRIPTION_PAGE_HILOS_PROFILE_SESSIONS = 'subscription_page_hilos_profile_sessions';

    public const string SUBSCRIPTION_PAGE_HILOS_PROFILE_DEVICES = 'subscription_page_hilos_profile_devices';

    /** Subscription signal for the profile's security page - the second factor (HIL-494). */
    public const string SUBSCRIPTION_PAGE_HILOS_PROFILE_SECURITY = 'subscription_page_hilos_profile_security';

    /** Subscription signal for Hilos settings page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings';

    /** Subscription signal for Hilos i18n hub page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N = 'subscription_page_hilos_i18n';

    /** Subscription signal for Hilos i18n languages list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGES = 'subscription_page_hilos_i18n_languages';

    /** Subscription signal for Hilos i18n countries list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRIES = 'subscription_page_hilos_i18n_countries';

    /** Subscription signal for Hilos i18n entities list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ENTITIES = 'subscription_page_hilos_i18n_entities';

    /** Subscription signal for Hilos i18n UI pages list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGES = 'subscription_page_hilos_i18n_ui_pages';

    /** Subscription signal for Hilos i18n groups list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUPS = 'subscription_page_hilos_i18n_groups';

    /** Subscription signal for Hilos i18n actions list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTIONS = 'subscription_page_hilos_i18n_actions';

    /** Subscription signal for Hilos i18n emails list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_EMAILS = 'subscription_page_hilos_i18n_emails';

    /** Subscription signal for Hilos i18n language detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE = 'subscription_page_hilos_i18n_language';

    /** Subscription signal for Hilos i18n country detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY = 'subscription_page_hilos_i18n_country';

    /** Subscription signal for Hilos i18n UI page detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGE = 'subscription_page_hilos_i18n_ui_page';

    /** Subscription signal for Hilos i18n group detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUP = 'subscription_page_hilos_i18n_group';

    /** Subscription signal for Hilos i18n action detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTION = 'subscription_page_hilos_i18n_action';

    /** Subscription signal for Hilos i18n translate entity page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ENTITY = 'subscription_page_hilos_i18n_translate_entity';

    /** Subscription signal for Hilos i18n translate UI page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE = 'subscription_page_hilos_i18n_translate_ui_page';

    /** Subscription signal for Hilos i18n translate UI page item. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = 'subscription_page_hilos_i18n_translate_ui_page_item';

    /** Subscription signal for Hilos i18n translate group page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP = 'subscription_page_hilos_i18n_translate_group';

    /** Subscription signal for Hilos i18n translate group item. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM = 'subscription_page_hilos_i18n_translate_group_item';

    /** Subscription signal for Hilos i18n translate action error page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR = 'subscription_page_hilos_i18n_translate_action_error';

    /** Subscription signal for Hilos i18n translate email page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_EMAIL = 'subscription_page_hilos_i18n_translate_email';

    /** Subscription signal for Hilos guardian page. */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN = 'subscription_page_hilos_guardian';

    /** Subscription signal for Hilos guardian AI agent page. */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT = 'subscription_page_hilos_guardian_agent';

    /** Subscription signal for Hilos analytics page. */
    public const string SUBSCRIPTION_PAGE_HILOS_ANALYTICS = 'subscription_page_hilos_analytics';

    /** Subscription signal for Hilos backup page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BACKUP = 'subscription_page_hilos_backup';

    /** Subscription signal for the Hilos maintenance section. */
    public const string SUBSCRIPTION_PAGE_HILOS_MAINTENANCE = 'subscription_page_hilos_maintenance';

    /** Subscription signal for Hilos daemon dashboard. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON = 'subscription_page_hilos_daemon';

    /** Subscription signal for Hilos daemon workers page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS = 'subscription_page_hilos_daemon_workers';

    /** Subscription signal for Hilos daemon agents page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_AGENTS = 'subscription_page_hilos_daemon_agents';

    /** Subscription signal for Hilos daemon cron page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_CRON = 'subscription_page_hilos_daemon_cron';

    /** Subscription signal for Hilos daemon websockets page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WEBSOCKETS = 'subscription_page_hilos_daemon_websockets';

    /** Subscription signal for Hilos daemon HTTP server page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_HTTP_SERVER = 'subscription_page_hilos_daemon_http_server';

    /** Subscription signal for Hilos logs overview page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS = 'subscription_page_hilos_logs';

    /** Subscription signal for Hilos logs by key page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS = 'subscription_page_hilos_logs_keys';

    /** Subscription signal for Hilos logs by worker page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS = 'subscription_page_hilos_logs_workers';

    /** Subscription signal for Hilos logs rotations page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS = 'subscription_page_hilos_logs_rotations';

    /** Subscription signal for Hilos logs viewer page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW = 'subscription_page_hilos_logs_view';

    /** Subscription signal for Hilos logging modes page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS = 'subscription_page_hilos_logs_settings';

    /** Subscription signal for Hilos operations page. */
    public const string SUBSCRIPTION_PAGE_HILOS_OPERATIONS = 'subscription_page_hilos_operations';

    /** Subscription signal for Hilos users list. */
    public const string SUBSCRIPTION_PAGE_HILOS_USERS = 'subscription_page_hilos_users';

    /** Subscription signal for Hilos single user page. */
    public const string SUBSCRIPTION_PAGE_HILOS_USER = 'subscription_page_hilos_user';

    /** Subscription signal for Hilos roles list. */
    public const string SUBSCRIPTION_PAGE_HILOS_ROLES = 'subscription_page_hilos_roles';

    /** Subscription signal for Hilos MCP and Skills hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS = 'subscription_page_hilos_mcp_skills';

    /** Subscription signal for Hilos single MCP page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP = 'subscription_page_hilos_mcp_skills_mcp';

    /** Subscription signal for Hilos MCP log overview page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS = 'subscription_page_hilos_mcp_skills_mcp_logs';

    /** Subscription signal for Hilos MCP log viewer page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW = 'subscription_page_hilos_mcp_skills_mcp_logs_view';

    /** Subscription signal for Hilos SIL dashboard. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL = 'subscription_page_hilos_sil';

    /** Subscription signal for Hilos SIL requests list. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_REQUESTS = 'subscription_page_hilos_sil_requests';

    /** Subscription signal for Hilos SIL user history page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_USER_HISTORY = 'subscription_page_hilos_sil_user_history';

    /** Subscription signal for Hilos communications hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS = 'subscription_page_hilos_communications';

    /** Subscription signal for Hilos communications channel page. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL = 'subscription_page_hilos_communications_channel';

    /** Subscription signal for Hilos communications deliveries page. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_DELIVERIES = 'subscription_page_hilos_communications_deliveries';

    /** Subscription signal for Hilos security hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY = 'subscription_page_hilos_security';

    /** Subscription signal for Hilos security 2FA page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_2FA = 'subscription_page_hilos_security_2fa';

    /** Subscription signal for Hilos OAuth providers list. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH = 'subscription_page_hilos_security_oauth';

    /** Subscription signal for Hilos OAuth provider detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH_PROVIDER = 'subscription_page_hilos_security_oauth_provider';

    /** Subscription signal for Hilos sign-in methods (HIL-427). */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_SIGN_IN_METHODS = 'subscription_page_hilos_security_sign_in_methods';

    /** Subscription signal for Hilos billing hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING = 'subscription_page_hilos_billing';

    /** Subscription signal for Hilos billing provider detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_PROVIDER = 'subscription_page_hilos_billing_provider';

    /** Subscription signal for Hilos billing payments page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_PAYMENTS = 'subscription_page_hilos_billing_payments';

    /** Subscription signal for Hilos billing refunds page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_REFUNDS = 'subscription_page_hilos_billing_refunds';

    /** Subscription signal for Hilos change log overview. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG = 'subscription_page_hilos_change_log';

    /** Subscription signal for Hilos change log tables list. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLES = 'subscription_page_hilos_change_log_tables';

    /** Subscription signal for Hilos change log table detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE = 'subscription_page_hilos_change_log_table';

    /** Wire signal name for incremental table row mutations. */
    public const string TABLE_MUTATION = 'table_mutation';

    // ── Hilos users admin: single-user rename action + acks (client ↔ server) ──
    /** Client → server: rename the displayed user (handled on the HILOS_USER page). */
    public const string HILOS_USER_UPDATE = 'hilos_user_update';

    /** Client → server: merge another account into the displayed user (handled on the HILOS_USER page). */
    public const string HILOS_USER_MERGE = 'hilos_user_merge';

    /** Server → initiator: hilos_user_update succeeded. */
    public const string HILOS_USER_UPDATE_SUCCESS = 'hilos_user_update_success';

    /** Server → initiator: hilos_user_update failed (carries a reason text). */
    public const string HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail';

    /**
     * Admin page → users library: rename this person (HIL-771).
     *
     * The write half of {@see self::HILOS_USER_UPDATE}, split off from it because the two
     * halves belong to different owners: WHO may rename is the page's ADMIN level, which an
     * agent action carries no equivalent of, and the account row is the library's. So the page
     * keeps the submit and forwards the work here. Carried by {@see AdminRenameSignalData},
     * which brings the waiting admin and the person doing the renaming along.
     */
    public const string HILOS_USER_ADMIN_RENAME = 'hilos_user_admin_rename';

    /**
     * Users library → admin page: the rename is done, or it is refused (HIL-771).
     *
     * The way back for {@see self::HILOS_USER_ADMIN_RENAME} and only for it: the page turns it
     * into the {@see self::HILOS_USER_UPDATE_SUCCESS} or {@see self::HILOS_USER_UPDATE_FAIL}
     * ack its own surface has always listened for. Carried by {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_USER_ADMIN_RENAME_DONE = 'hilos_user_admin_rename_done';

    // ── Hilos settings admin: table mutation actions (client → server) ──
    /** Client → server: add a setting override on the HILOS_SETTINGS page. */
    public const string SETTING_ADD = 'setting_add';

    /** Client → server: update a setting value on the HILOS_SETTINGS page. */
    public const string SETTING_UPDATE = 'setting_update';

    /** Client → server: delete an orphan setting on the HILOS_SETTINGS page. */
    public const string SETTING_DELETE = 'setting_delete';

    /** Client → server: reset a cataloged setting back to its catalog default on the HILOS_SETTINGS page. */
    public const string SETTING_RESET = 'setting_reset';

    /** Client → server: apply a named setting preset of the page's group. */
    public const string SETTING_PRESET_APPLY = 'setting_preset_apply';

    // ── Hilos backup admin: list-page actions (client → server) ──
    /** Client → server: start a backup with a chosen scope on the HILOS_BACKUP page. */
    public const string BACKUP_CREATE = 'backup_create';

    /** Client → server: delete a stored backup on the HILOS_BACKUP page. */
    public const string BACKUP_DELETE = 'backup_delete';

    /** Client → server: delete the marked backups, or every one matching a filter, as one bulk run. */
    public const string BACKUP_BULK_DELETE = 'backup_bulk_delete';

    /** Client → server: toggle a stored backup's rotation pin on the HILOS_BACKUP page. */
    public const string BACKUP_SET_KEEP = 'backup_set_keep';

    /**
     * Client → server: restore the named stored backup on the HILOS_BACKUP page (HIL-276).
     *
     * The destructive one, and the only page action the environment can withhold: the
     * button exists everywhere but production, where the page hands out the CLI command
     * instead. The refusal is recomputed on this action all the same — a client is not the
     * source of truth about where it runs.
     */
    public const string BACKUP_RESTORE = 'backup_restore';

    /**
     * Client → server: end the verification window a restore left the node in (HIL-676).
     *
     * Payload-less on purpose. The freeze it ends is named by the runtime row on the server,
     * and the one browser allowed to end it is named there too, so a client has nothing left
     * to say about it — the empty payload IS the gate. It is the page's half of what
     * `php cli.php protected-mode:open` does from a terminal, and it reaches the same agent
     * by the same road.
     */
    public const string BACKUP_REOPEN = 'backup_reopen';

    /**
     * Client -> server: name one more person to the verifier circle (HIL-643).
     *
     * Carries the address as the operator typed it and nothing else. Who that address
     * belongs to is resolved on the server against the confirmed identities, because the
     * circle is a list of people rather than a list of strings, and a client naming a user
     * id would be naming a row in a database a restore is about to replace.
     */
    public const string BACKUP_CIRCLE_ADD = 'backup_circle_add';

    /**
     * Client -> server: take one person out of the verifier circle (HIL-643).
     *
     * Carries the row key the table handed out, not the address shown beside it: what is
     * being removed is a membership, and the key that names it does not change with what the
     * screen happens to display.
     */
    public const string BACKUP_CIRCLE_REMOVE = 'backup_circle_remove';

    // ── Hilos maintenance section: the verifier circle (client → server) ──
    /**
     * Client -> server: name one more person to the verifier circle from the maintenance
     * section (HIL-1120).
     *
     * Carries the address as the operator typed it and nothing else. Who that address
     * belongs to is resolved on the server against the confirmed identities, and the circle
     * holds one person by one row: an address whose owner is already named under another
     * address is refused rather than added beside it.
     */
    public const string MAINTENANCE_CIRCLE_ADD = 'maintenance_circle_add';

    /**
     * Client -> server: take one person out of the verifier circle from the maintenance
     * section (HIL-1121).
     *
     * Carries the row key the table handed out, not the address shown beside it: what is
     * being removed is a membership, and the key that names it does not change with what the
     * screen happens to display. A key that names no row any more is refused rather than
     * answered with a silent success.
     */
    public const string MAINTENANCE_CIRCLE_REMOVE = 'maintenance_circle_remove';

    // ── Hilos backup admin: restore progress (server → the connection that asked) ──
    /**
     * BackupAgent → restore initiator: one snapshot of the restore runtime row.
     *
     * The freeze stops the page's own agent, so the table sends no deltas while a restore
     * runs and the initiator would otherwise watch a spinner that never moves. The agent
     * addresses this frame to the one connection protected mode keeps alive, on every phase
     * change and on the terminal outcome; it carries exactly what the CLI monitor is told, so
     * the two views of one run cannot disagree.
     */
    public const string BACKUP_RESTORE_PROGRESS = 'backup_restore_progress';

    // ── Hilos logs admin: the node that owns the files → the following viewer (WS_USER) ──
    /**
     * {@see LogStoreAgent} → the following viewer: what happened to the file since the last frame (HIL-389).
     *
     * Sent by the node that owns the file straight to the socket, which another node may be
     * holding, without going back through the page: the page has nothing to add and would only be
     * one more place for the frame to be lost.
     *
     * One of four things and never two at once - lines were appended, the file was rotated away
     * and reading restarted, the viewer fell so far behind that the owner jumped to the end, or the
     * follow ended on the owner's side. A tick with nothing to say sends nothing at all. Stamped
     * with the request id of the start, so frames of a follow the viewer has already replaced are
     * recognized and dropped. Carried by {@see LogsLinesAppendedSignalData}.
     */
    public const string LOGS_LINES_APPENDED = 'logs_lines_appended';

    // ── Hilos communications admin: channel-config actions (client → server) ──
    /**
     * Client → server: write one channel config field's settings override.
     *
     * Owned by the channel page; the hub's enablement toggle sends this same action
     * with the `enabled` field so a single owner routes both surfaces (an action name
     * is globally unique to one page).
     */
    public const string COMMUNICATIONS_CHANNEL_SET = 'communications_channel_set';

    /** Client → server: reset one channel config field to its env/default value. */
    public const string COMMUNICATIONS_CHANNEL_RESET = 'communications_channel_reset';

    /** Client → server: send a test notification narrowed to one channel. */
    public const string COMMUNICATIONS_CHANNEL_TEST = 'communications_channel_test';

    /**
     * Client → server: re-queue one failed channel delivery (HIL-201).
     *
     * Owned by the deliveries page; resets the failed delivery row to pending with
     * zero attempts and re-queues the channel's deliver signal.
     */
    public const string COMMUNICATIONS_DELIVERY_RETRY = 'communications_delivery_retry';

    // ── Hilos security admin: OAuth provider actions (client → server, HIL-286) ──
    /**
     * Client → server: write one field of one OAuth provider (client id, scope or secret).
     *
     * Owned by the provider page. The secret is accepted and replaced, and its answer
     * carries no value - only that it was written.
     */
    public const string SECURITY_OAUTH_PROVIDER_SET = 'security_oauth_provider_set';

    /** Client → server: take one field of one OAuth provider back to its env/recipe value. */
    public const string SECURITY_OAUTH_PROVIDER_RESET = 'security_oauth_provider_reset';

    /** Client → server: write the shared OAuth return address. Owned by the providers list page. */
    public const string SECURITY_OAUTH_REDIRECT_SET = 'security_oauth_redirect_set';

    /** Client → server: take the shared OAuth return address back to its env value. */
    public const string SECURITY_OAUTH_REDIRECT_RESET = 'security_oauth_redirect_reset';

    // ── Hilos security admin: sign-in methods action (client → server, HIL-427) ──
    /**
     * Client → server: switch one sign-in method on or off.
     *
     * Owned by the sign-in methods page, which rewrites the one list of switched-off methods
     * and asks the settings library to store it.
     */
    public const string SECURITY_SIGN_IN_METHOD_SET = 'security_sign_in_method_set';

    /**
     * Client → server: allow or refuse a passkey as the only way into an account on an unconfirmed address (HIL-1105).
     *
     * Owned by the sign-in methods page, which asks the settings library to store the one
     * yes-or-no setting; the answer comes back under the page's existing write-done name.
     */
    public const string SECURITY_PASSKEY_UNPROVEN_SET = 'security_passkey_unproven_set';

    // ── Hilos security admin: two-factor settings action (client → server, HIL-494) ──
    /**
     * Client → server: write one of the six second-factor settings.
     *
     * Owned by the two-factor page, which narrows the write to its own keys and asks the
     * settings library to store it; the setting's rule answers a refusal in the dialog.
     */
    public const string SECURITY_2FA_SETTING_SET = 'security_2fa_setting_set';

    /** Client → server: switch operation-level confirmation on or off. */
    public const string SECURITY_STEP_UP_OPERATION_SET = 'security_step_up_operation_set';

    // ── Hilos profile: second factor (client → server, signed in, HIL-494) ──
    /**
     * Client → server: start connecting an authenticator app. A further app is proven by a code
     * from one connected or a backup code; the answer hands the secret out once.
     */
    public const string PROFILE_SECOND_FACTOR_ENROLL_START = 'profile_second_factor_enroll_start';

    /** Client → server: the first code of the app being connected, and its name; the first app issues backup codes. */
    public const string PROFILE_SECOND_FACTOR_ENROLL_CONFIRM = 'profile_second_factor_enroll_confirm';

    /** Client → server: disconnect an app, proven by a code; the last one takes the whole factor with it. */
    public const string PROFILE_SECOND_FACTOR_REMOVE = 'profile_second_factor_remove';

    /** Client → server: show the backup codes, proven by a code. */
    public const string PROFILE_SECOND_FACTOR_CODES_SHOW = 'profile_second_factor_codes_show';

    /** Client → server: issue a new set of backup codes, proven by a code; the old set dies. */
    public const string PROFILE_SECOND_FACTOR_CODES_RENEW = 'profile_second_factor_codes_renew';

    /** Client → server: choose the removal wait; longer applies at once, shorter after the wait in force. */
    public const string PROFILE_SECOND_FACTOR_RESET_WAIT_SET = 'profile_second_factor_reset_wait_set';

    /** Client → server: ask the delayed removal of the second factor. */
    public const string PROFILE_SECOND_FACTOR_RESET_REQUEST = 'profile_second_factor_reset_request';

    /** Client → server: cancel the removal that stands. */
    public const string PROFILE_SECOND_FACTOR_RESET_CANCEL = 'profile_second_factor_reset_cancel';

    // ── Hilos profile: sign-in methods and email change (client → server, signed in, HIL-1137) ──
    /**
     * Client → server: change the password with the current one, or add one to a confirmed address.
     *
     * One action, two branches, chosen from the account's own ways in and never from the payload:
     * an account with a password proves the current one; one without adds a password to its
     * confirmed address. Renamed from the chat's bare `set_password`, which the sign-in flow's
     * step of the same name would have collided with.
     */
    public const string PROFILE_SET_PASSWORD = 'profile_set_password';

    /** Client → server: take one sign-in method off the account; the last one stays. Was the chat's `unlink_identity`. */
    public const string PROFILE_UNLINK_IDENTITY = 'profile_unlink_identity';

    /** Client → server: send a code to a phone the person wants to add as a way in. */
    public const string PROFILE_ADD_SMS_REQUEST = 'profile_add_sms_request';

    /** Client → server: the code that phone received; attaches it as a confirmed way in. */
    public const string PROFILE_ADD_SMS_CONFIRM = 'profile_add_sms_confirm';

    /** Client → server: send a code to the address a password is to be added on, for an account with no confirmed one. */
    public const string PROFILE_ADD_PASSWORD_REQUEST = 'profile_add_password_request';

    /** Client → server: the code that address received and the new password; adds the password on the proven address. */
    public const string PROFILE_ADD_PASSWORD_CONFIRM = 'profile_add_password_confirm';

    /** Client → server: send a code to the address the account holds now, to start changing it (HIL-299). */
    public const string PROFILE_CHANGE_EMAIL_CURRENT_REQUEST = 'profile_change_email_current_request';

    /** Client → server: check the current address's code without spending it. */
    public const string PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM = 'profile_change_email_current_confirm';

    /** Client → server: send a code to the new address, carrying the current address's code. */
    public const string PROFILE_CHANGE_EMAIL_NEW_REQUEST = 'profile_change_email_new_request';

    /** Client → server: prove the new address and move the account onto it. */
    public const string PROFILE_CHANGE_EMAIL_NEW_CONFIRM = 'profile_change_email_new_confirm';

    // ── Hilos profile: sign-in methods (server → client, WS_USER, HIL-1137) ──
    /**
     * Server → client: the person's password was added or changed; sent to every tab they have open.
     *
     * A change rewrites only a secret nothing projects, so without it no tab would learn the save
     * landed. Was the chat's `password_updated`.
     */
    public const string PROFILE_PASSWORD_UPDATED = 'profile_password_updated';

    // ── Hilos logs admin: viewer page actions (client → server) ──
    /**
     * Client → server: read one page of lines from one log file (HIL-757).
     *
     * Owned by the viewer page, which is where the browser is attached - the file itself lives
     * on the node the payload names, and the page forwards the request there rather than
     * answering it. The ack therefore comes from that node, correlated by this action's own
     * request id; the page owes none.
     *
     * The file is named structurally (source, batch stamp, stream) and never as a path, so the
     * browser cannot address the file system. Carried by {@see LogsReadLinesActionDTO}.
     */
    public const string LOGS_READ_LINES = 'logs_read_lines';

    // ── Hilos logs admin: viewer follow actions (client → server) ──
    /**
     * Client → server: start following the end of one live log file (HIL-389).
     *
     * Owned by the viewer page, and the same shape as {@see self::LOGS_READ_LINES}: the file lives
     * on the node the payload names, and the page forwards the request there. It answers with the
     * page of lines a read would have answered with and begins following from exactly that point,
     * because two calls - show me the end, now follow it - would lose whatever was written between
     * them. Returning to the tail from the mockup is this action again, not a third one.
     *
     * A rotated batch is never followed: nobody writes into one. Carried by
     * {@see LogsFollowStartActionDTO}.
     */
    public const string LOGS_FOLLOW_START = 'logs_follow_start';

    /**
     * Client → server: stop following, on the switch going off or the viewer scrolling up (HIL-389).
     *
     * Answered synchronously by the page, unlike the start: removal cannot fail in a way the
     * viewer could act on, and waiting for the owner to confirm would hold a browser in loading
     * for a fact about somebody else. Carried by {@see LogsFollowStopActionDTO}.
     */
    public const string LOGS_FOLLOW_STOP = 'logs_follow_stop';

    // ── Hilos logs admin: rotations page actions (client → server) ──
    /**
     * Client → server: confirm that one rotation batch has been carried off (HIL-483).
     *
     * Owned by the rotations page, and forwarded the way {@see self::LOGS_READ_LINES} is: the fact
     * is a marker file inside the batch directory, so only the node holding that directory can
     * write it, and only it can say whether the batch is still there to be confirmed. The ack
     * comes from that node; the page owes none.
     *
     * The batch is named by its node and its rotation stamp, never by a path — the same rule the
     * viewer's read is named by. Carried by {@see LogsTakeoutConfirmActionDTO}.
     */
    public const string LOGS_TAKEOUT_CONFIRM = 'logs_takeout_confirm';

    /**
     * Client → server: withdraw the word that one rotation batch has been carried off (HIL-759).
     *
     * The twin of {@see self::LOGS_TAKEOUT_CONFIRM}, forwarded the same way and for the same
     * reason: the fact is a marker file inside the batch directory, so only the node holding that
     * directory can take it away, and only it can say whether the batch is still there at all.
     *
     * Its own action and not a flag on the confirmation: the two are checked differently — one
     * refuses a batch the policy protects again, the other refuses nothing but a batch that is
     * gone — and one payload with a direction in it would branch on the node in its first line.
     * Carried by {@see LogsTakeoutUndoActionDTO}.
     */
    public const string LOGS_TAKEOUT_UNDO = 'logs_takeout_undo';

    // ── Hilos sign-in surface: guest commands (client → server) ──
    /** Client → server: look an identifier up while it is typed (public, anonymous-reachable). */
    public const string HILOS_DETECT_IDENTIFIER = 'hilos_detect_identifier';

    /** Client → server: email+password login (public, anonymous-reachable). */
    public const string HILOS_LOGIN = 'hilos_login';

    /** Client → server: email+password registration (public, anonymous-reachable). */
    public const string HILOS_REGISTER = 'hilos_register';

    /** Client → server: submit the confirmation code that creates the reserved account (public, anonymous-reachable, HIL-415). */
    public const string HILOS_CONFIRM_REGISTER = 'hilos_confirm_register';

    /** Client → server: resend the confirmation code of a pending registration (public, anonymous-reachable, HIL-415). */
    public const string HILOS_REQUEST_REGISTER_CONFIRM = 'hilos_request_register_confirm';

    /** Client → server: request a password-reset code (public, anonymous-reachable). */
    public const string HILOS_REQUEST_PASSWORD_RESET = 'hilos_request_password_reset';

    /** Client → server: submit a password-reset code, without the new password (public, anonymous-reachable). */
    public const string HILOS_CONFIRM_PASSWORD_RESET = 'hilos_confirm_password_reset';

    /** Client → server: save the new password of an accepted recovery (public, anonymous-reachable, HIL-416). */
    public const string HILOS_COMPLETE_PASSWORD_RESET = 'hilos_complete_password_reset';

    /** Client → server: save the first password of a proved registration, which creates the account (public, anonymous-reachable, HIL-825). */
    public const string HILOS_COMPLETE_REGISTRATION = 'hilos_complete_registration';

    /** Client → server: create the account of a proved registration with no password at all (public, anonymous-reachable, HIL-1008). */
    public const string HILOS_COMPLETE_REGISTRATION_PASSWORDLESS = 'hilos_complete_registration_passwordless';

    /**
     * Client → server: send a one-time login code to a phone over a chosen channel
     * (public, anonymous-reachable, HIL-492).
     *
     * Named after the identifier and not the transport since the code stopped being an
     * SMS by definition: the payload names the channel, and SMS is one entry of a
     * registry the project composes.
     */
    public const string HILOS_REQUEST_PHONE_CODE = 'hilos_request_phone_code';

    /** Client → server: submit a one-time login code for a phone (public, anonymous-reachable). */
    public const string HILOS_CONFIRM_PHONE_CODE = 'hilos_confirm_phone_code';

    /** Client → server: request an email magic-link sign-in token (public, anonymous-reachable). */
    public const string HILOS_REQUEST_MAGIC_LINK = 'hilos_request_magic_link';

    /** Client → server: submit an email magic-link sign-in token (public, anonymous-reachable). */
    public const string HILOS_CONFIRM_MAGIC_LINK = 'hilos_confirm_magic_link';

    /**
     * Client → server: submit the code that rode in the magic-link letter
     * (public, anonymous-reachable, HIL-606).
     *
     * A door of its own rather than the token action given a shorter string: the link and
     * the code are separate challenges with separate attempt ceilings, so one action would
     * force the server to guess which secret it was handed and spend an attempt of both on
     * every guess. Its payload names the code as such - { email, code }.
     */
    public const string HILOS_CONFIRM_MAGIC_LINK_CODE = 'hilos_confirm_magic_link_code';

    /**
     * Client → server: the way off a code screen, whatever intent opened it (public, anonymous-reachable, HIL-486).
     *
     * Ends what this SESSION was waiting on, in every tab of it at once, and frees the hold
     * this browser took on the identifier: pressing the way out says out loud that this
     * registration is not wanted, so typing the same address again starts a fresh one
     * (HIL-829). Only this browser's own hold goes - another session waiting on the same
     * identifier is still waiting on it. Walking away in silence is the other event and sends
     * nothing at all: that hold stands until it runs out on its own.
     */
    public const string HILOS_CANCEL_REGISTRATION = 'hilos_cancel_registration';

    /** Client → server: begin an OAuth login by minting the provider authorize URL (public, anonymous-reachable). */
    public const string HILOS_OAUTH_START = 'hilos_oauth_start';

    /** Client → server: hand back the OAuth provider code+state after the redirect (public, anonymous-reachable). */
    public const string HILOS_OAUTH_CALLBACK = 'hilos_oauth_callback';

    /**
     * Client → session holder: this connection is the tab that started the trip under this key.
     *
     * Sent after every reconnect while an exchange is running (HIL-1044). The outcome of a trip
     * is owed to the connection that sent the callback, and a reconnect replaces it; presenting
     * the key the tab minted moves the outcome onto the new connection and hands over whatever
     * already arrived - a sign-in included, which may only ever go to the tab that started it
     * (HIL-582). The reply says whether the holder knows the key at all.
     */
    public const string HILOS_OAUTH_RESUME = 'hilos_oauth_resume';

    /**
     * Client → agent (page-independent): the person has read the success ack an auth
     * flow left on this session, so clear it from every socket of the session (HIL-422).
     */
    public const string HILOS_DISMISS_SESSION_ACK = 'hilos_dismiss_session_ack';

    /**
     * Client → agent (page-independent): the person closed a toast the server raised, so take
     * it off every tab of this session (HIL-768).
     *
     * It names the card because a session can be shown several at once. Closing is not
     * optimistic: the tab sends this and keeps drawing the card until the frame that carries
     * the shortened stack arrives, so two windows never disagree about what is on screen.
     */
    public const string HILOS_TOAST_DISMISS = 'hilos_toast_dismiss';

    /**
     * Client → agent (page-independent): this tab's countdown for that card has burned down
     * (HIL-768).
     *
     * A report and not a removal. Each tab counts its own twenty seconds - the countdown is
     * deliberately unsynchronized, because a cursor resting on the stack here and not there is
     * two tabs rather than a fault - so the card goes only when a live socket has finished
     * counting AND no live socket is reading. The tab goes on showing it meanwhile.
     */
    public const string HILOS_TOAST_EXPIRED = 'hilos_toast_expired';

    /**
     * Client → agent (page-independent): the stack is (or is no longer) being read in this tab
     * (HIL-768).
     *
     * One name for both edges, because what it writes is a state rather than an event. Reading
     * is a cursor over the stack or the keyboard focus inside it, and a hidden tab is not
     * reading: it freezes its own countdown, but a background tab of the admin panel that held
     * the stack would make every toast immortal in the window being used.
     */
    public const string HILOS_TOAST_READING = 'hilos_toast_reading';

    /**
     * Client → sessions library (page-independent): revert this session to anonymous.
     *
     * Framework-owned since HIL-710, where the sign-out control stopped addressing a project
     * agent: the session is the library's, and the control sits in the app shell of every
     * project rather than on a page of one. The name is the framework's for the same reason
     * the action is - a project that kept its own would be naming a door it no longer holds.
     */
    public const string HILOS_LOGOUT = 'hilos_logout';

    /** Client → sessions library: end one other session owned by the acting user. */
    public const string HILOS_SESSION_END = 'hilos_session_end';

    /** Client → sessions library: end every other non-impersonating session of the acting user. */
    public const string HILOS_SESSIONS_END_OTHERS = 'hilos_sessions_end_others';

    /**
     * Client → sessions library: erase everything this browser holds and end its session (HIL-839).
     *
     * The server half of the erase on /privacy, and page-independent for the same reason
     * {@see self::HILOS_LOGOUT} is: the control sits on a public page a guest reaches as
     * readily as a signed-in person, and what it ends is the BROWSER session, which a guest
     * has like anybody else. The payload is empty, so a client can only ever erase its own.
     *
     * It is not a second sign-out. Signing out leaves the session row and its cookie in
     * place, anonymous, so a browser that "erased everything" would walk away carrying the
     * identifier it arrived with; this ends that session and hands the browser a new one,
     * through the rotation a sign-in already uses.
     */
    public const string HILOS_BROWSER_ERASE = 'hilos_browser_erase';

    /**
     * Client → Hilos users page: make this admin session act as another user (HIL-729,
     * moved onto the page by HIL-824).
     *
     * The name lives on {@see AbstractHilosUsersPage} because of what closes it: only an
     * administrator may take a person over, and an ADMIN level is a thing only a page
     * carries. It stood on the sessions library until HIL-824 on the strength of what it
     * writes - a session - and that is the writer's claim, not the gatekeeper's; the library
     * asked the project through a seam because it had no level to stand on. The wire name is
     * unchanged, so the browser sends the same string it always did.
     *
     * What it writes is still the library's, so the page forwards
     * {@see self::HILOS_IMPERSONATE_REQUEST} and answers the admin on
     * {@see self::HILOS_IMPERSONATE_DONE}. The seam stays as well, for the entrance that has
     * no page at all - an operator on the command line.
     */
    public const string HILOS_IMPERSONATE_START = 'hilos_impersonate_start';

    /**
     * Client → sessions library (page-independent): return this impersonating session to the
     * administrator behind it (HIL-729).
     *
     * The inverse of {@see self::HILOS_IMPERSONATE_START} and page-independent for the
     * stronger reason: while impersonating, the effective user is the non-admin target, so
     * the control has to live in the app shell and no page can be guaranteed under it. It
     * carries no payload and needs no seam - the administrator to go back to is read off the
     * session's own marker.
     */
    public const string HILOS_IMPERSONATE_STOP = 'hilos_impersonate_stop';

    /**
     * Hilos users page → sessions library: rebind this session onto that person (HIL-824).
     *
     * The write half of {@see self::HILOS_IMPERSONATE_START}, split off from it for the
     * reason the neighbouring pairs were split: WHO may ask is the page's ADMIN level, which
     * an agent action carries no equivalent of, and the session being rebound is the
     * library's. So the gatekeeper checks who is asking and the owner does the writing.
     *
     * Nothing is judged on the way out, not even that the admin session still exists: the
     * page would be reading in one worker what another is free to change before it writes.
     * Carried by {@see ImpersonateRequestSignalData}, which brings the waiting admin along.
     */
    public const string HILOS_IMPERSONATE_REQUEST = 'hilos_impersonate_request';

    /**
     * Sessions library → Hilos users page: the takeover happened, or it is refused
     * (HIL-824).
     *
     * The way back for {@see self::HILOS_IMPERSONATE_REQUEST} and only for it. The page
     * deferred its own ack when it handed the work over, so this frame is what finally
     * answers the admin - the takeover, or the sentence saying why the session was not
     * rebound. The refusal has to travel as text: after the move the guards run outside a
     * page, and the dispatcher's exception hook does not reach there. Carried by
     * {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_IMPERSONATE_DONE = 'hilos_impersonate_done';

    // ── Hilos sign-in surface: WebAuthn ceremonies (client → server) ──
    /**
     * Client → server: request WebAuthn discoverable (usernameless) login options — no
     * email, empty allowCredentials (public, anonymous-reachable, HIL-400).
     */
    public const string HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS = 'hilos_passkey_discoverable_login_options';

    /** Client → server: submit a WebAuthn login assertion to sign in (public, anonymous-reachable, HIL-284). */
    public const string HILOS_PASSKEY_LOGIN_CONFIRM = 'hilos_passkey_login_confirm';

    /** Client → server: request WebAuthn registration options for the signed-in user (authenticated, HIL-284). */
    public const string HILOS_PASSKEY_REGISTER_OPTIONS = 'hilos_passkey_register_options';

    /** Client → server: submit a WebAuthn registration attestation to store a new passkey (authenticated, HIL-284). */
    public const string HILOS_PASSKEY_REGISTER_CONFIRM = 'hilos_passkey_register_confirm';

    // ── Hilos sign-in surface: second factor (client → server, HIL-494) ──
    /**
     * Client → server: the code of a sign-in held on its second factor - from an authenticator
     * app or a backup code - with the choice to trust this browser (anonymous-reachable, throttled).
     */
    public const string HILOS_CONFIRM_SECOND_FACTOR = 'hilos_confirm_second_factor';

    /** Client → server: go back from a second-factor screen and sign in another way (anonymous-reachable). */
    public const string HILOS_CANCEL_SECOND_FACTOR = 'hilos_cancel_second_factor';

    /** Client → server: a fresh secret for the enrolment an administrator requires on the way in. */
    public const string HILOS_SECOND_FACTOR_SETUP_START = 'hilos_second_factor_setup_start';

    /** Client → server: the first code of that enrolment and the name of the app (throttled). */
    public const string HILOS_SECOND_FACTOR_SETUP_CONFIRM = 'hilos_second_factor_setup_confirm';

    /** Client → server: the Continue under the backup codes of that enrolment - let the person in. */
    public const string HILOS_SECOND_FACTOR_SETUP_FINISH = 'hilos_second_factor_setup_finish';

    /** Client → server: ask the delayed removal of the second factor from the code step. */
    public const string HILOS_SECOND_FACTOR_RESET_REQUEST = 'hilos_second_factor_reset_request';

    /** Client → server: the "it was not me" link of a delayed removal, without signing in (throttled). */
    public const string HILOS_SECOND_FACTOR_RESET_CANCEL_LINK = 'hilos_second_factor_reset_cancel_link';

    // ── Hilos operation step-up (client → server, signed in, HIL-495) ──
    /** Client → server: resolve and, when needed, begin proof for one protected operation. */
    public const string HILOS_STEP_UP_START = 'hilos_step_up_start';

    /** Client → server: submit the proof selected while opening a protected operation. */
    public const string HILOS_STEP_UP_CONFIRM = 'hilos_step_up_confirm';

    // ── Hilos account deletion (client → server, signed in, HIL-302) ──
    /**
     * Client → server: open the deletion window - the grace period and where the code goes.
     *
     * Payload {}; the answer is {graceDays, channel: 'email'|'phone'|null, destination: string|null},
     * carried by {@see AccountDeletionOpeningReplyDTO}. Refused while a deletion is scheduled.
     */
    public const string HILOS_ACCOUNT_DELETION_OPEN = 'hilos_account_deletion_open';

    /** Client → server: send the code that confirms the deletion to the account's address. Payload {}. */
    public const string HILOS_ACCOUNT_DELETION_CODE = 'hilos_account_deletion_code';

    /** Client → server: start the deletion with the code the address received (throttled). Payload {code}. */
    public const string HILOS_ACCOUNT_DELETION_START = 'hilos_account_deletion_start';

    /**
     * Client → server: call off the scheduled deletion. Payload {}.
     *
     * Needs no fresh proof and stands outside the product's guard, so a frozen account can
     * call it off too; an impersonated session is refused.
     */
    public const string HILOS_ACCOUNT_DELETION_CANCEL = 'hilos_account_deletion_cancel';

    // ── Hilos profile: OAuth account linking (client → server) ──
    /** Client → server: begin linking an OAuth provider to the signed-in account (authenticated, HIL-401). */
    public const string HILOS_LINK_OAUTH_START = 'hilos_link_oauth_start';

    /** Client → server: redeem an OAuth link token after re-auth to link the account (authenticated, HIL-282). */
    public const string HILOS_LINK_OAUTH_AFTER_REAUTH = 'hilos_link_oauth_after_reauth';

    // ── Hilos sign-in surface: flow answers (server → client) ──
    /** Server → client: a parked sign-in surface moves because the identifier it waits on resolved (HIL-415). */
    public const string HILOS_AUTH_CONVERGE = 'hilos_auth_converge';

    /** Server → client: WebAuthn publicKey options + signed challenge for a passkey ceremony (HIL-284). */
    public const string HILOS_PASSKEY_OPTIONS = 'hilos_passkey_options';

    // ── Hilos OAuth login: async agent → initiating browser (WS_USER) ──
    /**
     * Session holder → the tab that started the trip: the provider sign-in ended without a
     * sign-in.
     *
     * The only OAuth outcome that needs its own signal: success rides the existing
     * session/currentUser fan-out (HIL-161), so the SPA callback surface resolves on
     * EITHER currentUser (login) OR this signal (see HIL-281 mechanism B). Sent by the holder
     * since HIL-1044, because the holder is the one that knows which connection the tab is on
     * now; the agents that reach the ending report it there on {@see HILOS_OAUTH_TRIP_ENDED}.
     */
    public const string HILOS_OAUTH_RESULT = 'hilos_oauth_result';

    /**
     * OAuth start action → initiating connection: the provider authorize URL to navigate to.
     *
     * The `oauthStart` page action does no outbound HTTP, but the framework's
     * `action_success` carries no domain payload, so the authorize URL cannot ride
     * the action ack. It is delivered on this WS_USER signal instead; the SPA
     * navigates the browser to `authorizeUrl` on receipt (see HIL-281 mechanism B).
     */
    public const string HILOS_OAUTH_AUTHORIZE = 'hilos_oauth_authorize';

    /**
     * OAuth callback action → the monopoly OAuth agent: hand off one verified pending login.
     *
     * The callback runs on a worker page while the OAuth agent is a leader-pinned
     * monopolistic singleton in another process (see HIL-281 mechanism B), so the
     * verified pending op is handed to it point-to-point over this agent signal — a
     * synced route with exactly one consumer — rather than through a cross-process
     * runtime collection. The single agent owns the in-flight-login pool it drains.
     */
    public const string HILOS_OAUTH_PENDING = 'hilos_oauth_pending';

    /**
     * OAuth agent → the users library: the provider answered, resolve the account.
     *
     * The other end of the exchange {@see HILOS_OAUTH_PENDING} starts. Who the provider
     * says this is arrives here as plain facts - subject, address, display name - and the
     * library turns them into an account, because writing the user set is what a library
     * owns and the OAuth agent, which is busy with network round-trips, must not (HIL-622).
     * Carried by {@see OAuthLoginReadySignalData}; a singleton route, since the library is
     * monopolistic.
     */
    public const string HILOS_OAUTH_LOGIN_READY = 'hilos_oauth_login_ready';

    /**
     * Users library → session holder: a tab is now waiting on a provider sign-in (HIL-1044).
     *
     * Sent from the callback BEFORE the exchange is handed to the OAuth agent, by the same
     * sender in the same tick, so the holder has the trip on record before anything can end it.
     * It carries the hash of the key the tab minted, never the key: the key is for the one
     * presentation that proves a new connection is that tab ({@see HILOS_OAUTH_RESUME}).
     */
    public const string HILOS_OAUTH_TRIP_OPENED = 'hilos_oauth_trip_opened';

    /**
     * OAuth agent or users library → session holder: a provider sign-in ended without signing
     * anybody in (HIL-1044).
     *
     * Every ending that is not a session grant: the exchange failed, a link settled, the address
     * collided with an account and needs re-authentication, the completion broke. The holder
     * records the first one and tells the tab on {@see HILOS_OAUTH_RESULT} - now, when its
     * connection is alive, or when it presents its key after a reconnect.
     */
    public const string HILOS_OAUTH_TRIP_ENDED = 'hilos_oauth_trip_ended';

    // ── Agent lifecycle: master → the agent that declared it (agent signal) ──
    /**
     * Master → the agent declaring this name: these agents are gone (HIL-1044).
     *
     * Sent where the master used to settle a lost agent with a log line and nothing else: after
     * it answered the frames held for agents whose worker died, after a start failed, and after
     * the leader placed an agent nowhere. Whoever was answering somebody on those agents' behalf
     * learns it here, as a fact, instead of by a clock. Never sent when nobody declares the name,
     * nor when the declaring agent is itself among the gone - a frame about its own death would
     * start the failing agent again and again.
     */
    public const string HILOS_AGENTS_GONE = 'hilos_agents_gone';

    // ── Hilos users library → the agent that holds sessions (agent signals) ──
    /**
     * Users library → the session holder: bind this session to this user, then answer.
     *
     * Where every sign-in command that ends in a signed-in person lands - password login,
     * a magic link, a phone code, a passkey, a proven address. The library owns the user
     * set and the proof; the holder owns sessions and the sockets standing on them, and a
     * handshake is routed to exactly one agent, so the two cannot be the same process
     * (HIL-622). The ORDER is the point of the frame: the holder marks, authenticates and
     * only then answers the action, because "done" reaching the client before its session
     * changes is a client that reads its own identity wrong.
     * Carried by {@see AuthSessionGrantSignalData}.
     */
    public const string HILOS_AUTH_SESSION_GRANT = 'hilos_auth_session_grant';

    /**
     * Users library → the session holder: this registration landed, settle everyone waiting.
     *
     * A registration is the one flow with more than one browser in it: other tabs and other
     * sessions may be parked on the same identifier, and only the holder can see them - the
     * waits are its runtime rows and the sockets are its connections. So the library sends
     * the outcome and the losers' tokens, and the holder converges them.
     * Carried by {@see AuthRegistrationLandedSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_LANDED = 'hilos_auth_registration_landed';

    /**
     * Users library → the session holder: this browser now waits on THAT address.
     *
     * Sent whenever a command parks a browser on a registration code step, and it exists
     * for the one case parking cannot answer for itself (HIL-685): the row is already
     * there and points somewhere else. Editing it is the holder's, because the holder is
     * the collection's one full truth source and the library holds adding and removing
     * only, so the library adds what is missing and says the rest here. One-way, and
     * idempotent - a frame repeating what the row says writes nothing.
     * Carried by {@see AuthRegistrationWaitMovedSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_WAIT_MOVED = 'hilos_auth_registration_wait_moved';

    /**
     * Code agent → the session holder: a code went out to a free number, so this session now
     * waits on registering it (HIL-1044).
     *
     * The code agent used to write that wait onto the session row itself, under a claim it
     * borrowed from the holder. The holder is the one that writes what a browser waits on now,
     * so the agent says it here instead - before the closing step of the send, from the same
     * sender, so a line replayed after the step already finds the wait written. It names the
     * session by its token, as the neighbouring frame does: the agent is handed the token with
     * the order, and a step of the line names no session at all. Carried by
     * {@see AuthRegistrationWaitHeldSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_WAIT_HELD = 'hilos_auth_registration_wait_held';

    /**
     * Users library → the session holder: this recovery is granted, move its tabs along.
     *
     * The recovery counterpart of {@see HILOS_AUTH_REGISTRATION_LANDED}: the code was
     * proved in the library, and the tabs sitting on the code screen belong to the holder.
     * Carried by {@see AuthRecoveryGrantedSignalData}.
     */
    public const string HILOS_AUTH_RECOVERY_GRANTED = 'hilos_auth_recovery_granted';

    /**
     * Users library → the session holder: this registration's address is proved, move its tabs along.
     *
     * The registration counterpart of {@see HILOS_AUTH_RECOVERY_GRANTED} (HIL-825): the
     * code was proved in the library and no account exists yet, so what changes is only
     * which screen the tabs of that browser are on. Carried by
     * {@see AuthRegistrationProvenSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_PROVEN = 'hilos_auth_registration_proven';

    /**
     * Users library → the session holder: this browser now recovers THAT address.
     *
     * The recovery counterpart of {@see HILOS_AUTH_REGISTRATION_WAIT_MOVED}, and it
     * carries one consequence more: the grant belongs to the address it was earned for,
     * so re-pointing a waiter drops it. That is the whole reason a second code asked for
     * on another address from the same tab cannot open the password step of the first.
     * Carried by {@see AuthRecoveryWaitMovedSignalData}.
     */
    public const string HILOS_AUTH_RECOVERY_WAIT_MOVED = 'hilos_auth_recovery_wait_moved';

    /**
     * Users library → the session holder: the password changed, drop the other sessions.
     *
     * A changed password ends every session but the one that changed it, and sessions are
     * the holder's. Carried by {@see AuthPasswordChangedSignalData}.
     */
    public const string HILOS_AUTH_PASSWORD_CHANGED = 'hilos_auth_password_changed';

    /**
     * Users library → the session holder: a wrong second-factor code, count it (HIL-494).
     *
     * The count lives on the session row; at the ceiling the holder lets the wait go and sends
     * the browser's tabs back to the address field. Carried by {@see AuthSecondFactorMissedSignalData}.
     */
    public const string HILOS_AUTH_SECOND_FACTOR_MISSED = 'hilos_auth_second_factor_missed';

    /**
     * Users library → the session holder: the enrolment on the way in is confirmed (HIL-494).
     *
     * Moves the wait to its last screen, so a reload serves the code step rather than a second
     * enrolment. Carried by {@see AuthSecondFactorSetupProvenSignalData}.
     */
    public const string HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN = 'hilos_auth_second_factor_setup_proven';

    /**
     * Users library → the session holder: this person's second factor is gone (HIL-494).
     *
     * Drops the browsers trusted to skip the step and lets every sign-in of the person still
     * waiting on a code go. Carried by {@see AuthSecondFactorOffSignalData}.
     */
    public const string HILOS_AUTH_SECOND_FACTOR_OFF = 'hilos_auth_second_factor_off';

    /**
     * Users library → the session holder: let this browser's second-factor wait go, and answer (HIL-494).
     *
     * Carried by {@see AuthSecondFactorCancelSignalData}.
     */
    public const string HILOS_AUTH_SECOND_FACTOR_CANCEL = 'hilos_auth_second_factor_cancel';

    /**
     * Users library → the session holder: this browser canceled its registration.
     *
     * Drops the pending registration of the session and the waits standing on it. The
     * reservation is already gone by the time this frame is sent: the users library owns
     * the holds and releases this session's own before it hands off (HIL-829).
     * Carried by {@see AuthRegistrationCanceledSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_CANCELED = 'hilos_auth_registration_canceled';

    // ── Hilos code channels: worker → code agent, code agent → guest browser ──
    /**
     * Page action requesting a phone code → the code agent: send one code over one channel.
     *
     * Requesting a code became asynchronous for every channel (HIL-492), because
     * deciding whether a channel can reach a number is a network round-trip on some
     * of them, and a page action may not wait on the network. The action validates
     * only what costs nothing (a well-formed number, a channel that exists and serves
     * this kind of identifier), hands the rest over this signal and acks; the agent
     * probes, mints and delivers across ticks. Carried by
     * {@see AuthCodeSendSignalData}; a singleton route, since the code agent is
     * monopolistic.
     */
    public const string HILOS_AUTH_CODE_SEND = 'hilos_auth_code_send';

    /**
     * Settings library → every connection: the installation's enabled sign-in methods (HIL-427).
     *
     * Sent after a settings write that changed the set, whichever door it came through - the
     * sign-in methods screen, the general settings table, a preset - so every open sign-in
     * surface rebuilds itself without asking. ws_all_connected, because a guest on the sign-in
     * page is subscribed to no page the set belongs to. Carried by
     * {@see AuthMethodsSignalData}; the handshake carries the same entries for a connection
     * that opens later. The frame also carries whether a passkey may start an account on an
     * unconfirmed address (HIL-1105), and a write that moved only that is sent the same way.
     */
    public const string HILOS_AUTH_METHODS = 'hilos_auth_methods';

    /**
     * Server → client (WS_GROUP): the profile's second-factor section of one person, whole (HIL-494).
     *
     * Fanned to the person's {@see SecondFactorGroup} after every write to their second factor,
     * from whichever process made it. Carried by {@see SecondFactorStateSignalData}.
     */
    public const string HILOS_SECOND_FACTOR_STATE = 'hilos_second_factor_state';

    /**
     * Server → client (WS_GROUP): one person's account deletion state, whole (HIL-302).
     *
     * Fanned to the person's {@see AccountDeletionGroup} after every start and cancel, so every
     * open tab turns the danger zone into the warning and back without a reload. Payload
     * {deletion: {requestedAt, effectiveAt} | null}, carried by {@see AccountDeletionStateSignalData}.
     */
    public const string HILOS_ACCOUNT_DELETION_STATE = 'hilos_account_deletion_state';

    /**
     * Server → client (all connected): the administrator's second-factor settings changed (HIL-494).
     *
     * Sent by the settings library after a write that moved them, so the profile section and the
     * code step redraw their bounds, note and checkbox. Carried by {@see SecondFactorPolicySignalData}.
     */
    public const string HILOS_SECOND_FACTOR_POLICY = 'hilos_second_factor_policy';

    /**
     * Sessions library → every tab of one browser session: this is how the code is travelling
     * (HIL-826).
     *
     * Addressed to the SESSION rather than to a socket, for the reason the toast stack is
     * ({@see HILOS_SESSION_TOASTS}): the line has to read the same after a reload and in a
     * second tab of that browser, and read nowhere else at all. It carries the whole line, so
     * a reconnect and an ordinary step are one sentence; a frame with a null state is the
     * legal one that takes the line away. Carried by {@see CodeSendProgressSignalData}.
     */
    public const string HILOS_CODE_SEND_PROGRESS = 'hilos_code_send_progress';

    /**
     * Whoever is carrying the code → the sessions library: the send has reached this step
     * (HIL-826).
     *
     * The one door every state of the line is born through, including the `queued` the caller
     * that ordered the code reports before any transport has seen it. The senders are the
     * sign-in commands, the per-channel mail queue and the code agent, and none of them writes
     * the line itself: it is the session's row, and the library that owns sessions owns it.
     * The frame names the send by an opaque ticket, which is the whole of what the mail
     * subsystem learns about auth. Carried by {@see CodeSendStepSignalData}.
     */
    public const string HILOS_CODE_SEND_STEP = 'hilos_code_send_step';

    // ── Hilos uploads: one connection ⇄ the uploads agent (HIL-135) ──────────
    /**
     * Client → uploads agent: declare a file; tracked; success = send its chunks.
     *
     * An agent action rather than a page one, because an upload lives on the connection and not
     * on the page that started it: a navigation inside the application does not cut it. Carried
     * by {@see UploadInitActionDTO}; every refusal of the start is the action's own error, and no
     * upload row is left behind by one.
     */
    public const string HILOS_UPLOAD_INIT = 'hilos_upload_init';

    /**
     * Client → uploads agent: drop one upload and its file; idempotent.
     *
     * Cancelling an upload that is already gone succeeds: the cancel races the upload's own
     * completion and the agent's cleanup, and whichever came first has done what was asked.
     * Carried by {@see UploadCancelActionDTO}.
     */
    public const string HILOS_UPLOAD_CANCEL = 'hilos_upload_cancel';

    /**
     * Uploads agent → the one connection: the WHOLE state of one upload; phase null = the upload is gone.
     *
     * Sent on every change of the row - the phase, the throttled progress, a failure - and
     * always whole, so a frame that was missed costs nothing the next one does not repair. A
     * refused declaration never travels here: it is the answer of the action. Carried by
     * {@see UploadStateSignalData}.
     */
    public const string HILOS_UPLOAD_STATE = 'hilos_upload_state';

    /**
     * Project → uploads agent: hand these complete uploads of one connection over to the files
     * registry; the answer comes under the name the frame carries (HIL-136).
     *
     * Sent by {@see HilosFiles::publishUploads()}. The agent checks every named upload before it
     * touches any; a refusal is answered at once, and uploads that pass leave their rows - their
     * files go on to the files library in one {@see self::HILOS_FILE_PUBLISH}. Carried by
     * {@see UploadPublishSignalData}; the answer is a {@see FilesPublishedSignalData}.
     */
    public const string HILOS_UPLOAD_PUBLISH = 'hilos_upload_publish';

    // ── Hilos auth throttle: worker ⇄ throttle agent (agent signals) ─────────
    /**
     * Worker dispatching a throttled action → the throttle agent: judge this attempt.
     *
     * The slow half of the two-step guard. The fast half needs nobody: a block already
     * consummated is visible in the worker's own replica of the attempt counters, and the
     * action is refused there. Everything else has to be counted, and only one process may
     * count - the agent that owns the collection - so the worker parks the action and asks.
     * Sent with {@see SignalSource::WORKER}, which an agent signal has accepted since
     * HIL-567.
     */
    public const string HILOS_AUTH_THROTTLE_CHECK = 'hilos_auth_throttle_check';

    /**
     * Throttle agent → the page agent holding the deferred action: it may run, or it may not.
     *
     * Addressed by the request key the check carried, because that is what the waiting pool
     * is keyed by. A verdict that never arrives is not an answer of "deny": the pool's own
     * deadline runs the action, since a lost signal is this server's fault rather than
     * evidence against the client.
     */
    public const string HILOS_AUTH_THROTTLE_VERDICT = 'hilos_auth_throttle_verdict';

    /**
     * Worker → throttle agent: this session authenticated, so stop holding its attempts
     * against it.
     *
     * Sent where a session is promoted to a user, which is the one moment the framework can
     * tell a suspicious sequence of attempts from a person who simply mistyped a password.
     * Only the session scope is forgiven; the address the attempts came from keeps its count,
     * because one sign-in behind a NAT says nothing about the rest of the crowd on it.
     */
    public const string HILOS_AUTH_THROTTLE_SUCCEEDED = 'hilos_auth_throttle_succeeded';

    // ── Hilos session rotation: session seam → initiating browser (WS_USER) ──
    /**
     * Session seam → the connection that logged in: trade this ticket for the new session cookie.
     *
     * The login rotated the session onto a token the browser has not been told about, and
     * cannot be told about directly: the session cookie is HttpOnly, so only a Set-Cookie
     * can write it, and the master emits one only on the 101. This signal carries the
     * one-time ticket ({@see SessionRotateSignalData}) that the frontend parks in a
     * short-lived helper cookie before reconnecting at once; the master trades it for the
     * rotated token on the handshake that follows and burns it.
     *
     * Delivered to the initiating connection alone. It is the rotation's only channel, and
     * a second recipient would be a second holder of a single-use secret.
     */
    public const string HILOS_SESSION_ROTATE = 'hilos_session_rotate';

    // ── Hilos session seam: sessions library ↔ the project agent holding the sockets ──
    /**
     * Sessions library → project agent: this is what the session is now, tell its sockets.
     *
     * The one frame the library answers with, whatever moved the session - a handshake, a
     * sign-in, a sign-out, an impersonation, a success ack that was raised or dismissed
     * (HIL-710). It names a LIST of sockets because the mechanics behind it are
     * per-session: deauthenticate, mark and clear all bring every live socket of one
     * session to a single state, and a handshake is simply the case where that list holds
     * one. Carried by {@see SessionStateSignalData}.
     *
     * What the project does with it is its half of the seam and is ordered: write the rows
     * first, then send its own handshake response, then the rotation ticket the frame may
     * carry, then the page re-decision, and the answer to the action last of all - behind
     * the identity it announces.
     */
    public const string HILOS_SESSION_STATE = 'hilos_session_state';

    /**
     * Sessions library → project agent: these browser session rows were swept.
     *
     * Carries the tokens of rows removed after their cookie lifetime ended or after an
     * anonymous browser never returned ({@see SessionsSweptSignalData}). A project agent
     * declares this frame when it stores data by browser token; when nobody declares it,
     * the library sends nothing.
     */
    public const string HILOS_SESSIONS_SWEPT = 'hilos_sessions_swept';

    /**
     * Sessions library → every tab of one browser session: this is the whole toast stack now
     * (HIL-768).
     *
     * Addressed to the SESSION rather than to a socket, because that is the thing the tabs of
     * one browser have to agree about: a card closed in the second window is closed in the
     * first, and one whose countdown ran out somewhere is gone from both. It carries the list
     * whole, so a reconnect, a second tab and an ordinary removal are one sentence; an empty
     * list is the legal frame that takes the last card away. Carried by
     * {@see SessionToastsSignalData}.
     */
    public const string HILOS_SESSION_TOASTS = 'hilos_session_toasts';

    /**
     * Sender → sessions library: raise this toast for that browser session (HIL-768).
     *
     * The one door a toast of the session is born through. Whoever has something to say names
     * the session by the hash of its cookie token and the sentence to show; the library mints
     * the card's name, counts a repeat of an identical one, and judges when it goes away -
     * being the only process that knows which sockets the session has. Carried by
     * {@see RaiseSessionToastSignalData}.
     */
    public const string HILOS_SESSION_TOAST_RAISE = 'hilos_session_toast_raise';

    /**
     * Project agent → sessions library: make this session say this instead.
     *
     * The way back over the same seam, and the only write a project may ask for on a
     * session it does not own (HIL-710): sign this session in as that person, sign it out,
     * put it behind an impersonating administrator or take it back out. It carries the
     * TARGET state whole rather than a change to apply - naming what the session should be
     * leaves no room for a third meaning of null beside "nobody" - and the library derives
     * the operation from it. Carried by {@see SessionRebindSignalData}.
     */
    public const string HILOS_SESSION_REBIND = 'hilos_session_rebind';

    /**
     * {@see BackupAgent} → sessions library: here are the logins a restore left for you (HIL-846).
     *
     * The deferred carry-over queue stays in the backup directory of the node that wrote it, and
     * the agent holding it offers the batch over this signal until the library answers - the
     * library no longer reads the file off its own disk, which in a cluster may be another disk.
     * The library re-creates the rows and answers {@see self::BACKUP_AGENT_SESSIONS_CARRIED} on
     * every branch, the failed one included. Carried by
     * {@see DeferredSessionCarryoverHandoverSignalData}.
     */
    public const string HILOS_SESSION_CARRYOVER_HANDOVER = 'hilos_session_carryover_handover';

    /**
     * Hilos user page → sessions library: fold this account into that one (HIL-411).
     *
     * The browser's way into the same core as {@see CliCommands::ACCOUNT_MERGE}. The single-user
     * page keeps the ADMIN gate and forwards the write because the merge ends in the loser's
     * live sessions being signed out, which is the sessions library's state. Carried by
     * {@see AccountMergeSignalData}, including the waiting admin and the answer name.
     */
    public const string HILOS_ACCOUNT_MERGE = 'hilos_account_merge';

    /**
     * Sessions library → Hilos user page: the merge is done, or it is refused (HIL-411).
     *
     * The way back for {@see self::HILOS_ACCOUNT_MERGE} and only for it. The page deferred its
     * tracked action reply when it handed the work over, so this frame finally answers the admin
     * with the library's outcome. Carried by {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_ACCOUNT_MERGE_DONE = 'hilos_account_merge_done';

    // ── Hilos files registry: the project → the files library (agent signal) ──
    /**
     * {@see HilosFiles::markBound()} → files library: these files are linked by the project now;
     * no answer (HIL-336).
     *
     * The project has already written its own link to each file, so there is nothing for it to
     * wait for; a frame lost on the way is caught by the janitor, which reads a foreign-key
     * refusal as the same fact. Carried by {@see FileBindSignalData}.
     */
    public const string HILOS_FILE_BIND = 'hilos_file_bind';

    /**
     * Uploads agent → files library: keep these handed-over temporary files and register them
     * unbound; answer the asker under its name (HIL-136).
     *
     * All or nothing: a failure on one file undoes what the request did so far and deletes the
     * temporary files still left. Carried by {@see FilePublishSignalData}; the answer is a
     * {@see FilesPublishedSignalData} under the name the project gave
     * {@see self::HILOS_UPLOAD_PUBLISH}.
     */
    public const string HILOS_FILE_PUBLISH = 'hilos_file_publish';

    // ── Hilos notification seam: any worker → the notifications library (agent signal) ──
    /**
     * {@see HilosNotifier::emit()} → notifications library: write this notification and deliver it.
     *
     * The one frame the emit seam became (HIL-771). Emitting used to be a write from whichever
     * worker happened to call the facade, which held only as long as the process it ran in also
     * hosted an owner of the notification tables - true by accident, never by design. The draft
     * now travels to the library instead, which writes the row, fans the live in-app frame to the
     * recipient's group and hands the row to {@see NotificationDispatcher}, in that order.
     *
     * It answers nobody on purpose: the caller no longer learns the id, because a caller that
     * waited for one would be waiting on another process for something no product path reads.
     * The one caller that does need it - the test-only emit command - is answered by the library,
     * which runs it. Carried by {@see NotificationEmitSignalData}.
     */
    public const string HILOS_NOTIFICATION_EMIT = 'hilos_notification_emit';

    /**
     * {@see BackupAgent} → notifications library: here are the notices a restore left for you
     * (HIL-846).
     *
     * The other half of {@see self::HILOS_SESSION_CARRYOVER_HANDOVER}, for the restore outcome
     * letters queued while nobody could be told. The library emits each draft exactly as if it
     * had arrived over {@see self::HILOS_NOTIFICATION_EMIT} and answers
     * {@see self::BACKUP_AGENT_NOTICES_SENT}. Carried by
     * {@see DeferredNotificationHandoverSignalData}.
     */
    public const string HILOS_NOTIFICATION_HANDOVER = 'hilos_notification_handover';

    /**
     * Deliveries page → notifications library: re-queue this failed delivery (HIL-771).
     *
     * The admin half of the retry, and the reason it is a frame rather than a call: the page
     * keeps the action and the ADMIN level that closes it, because an agent action carries no
     * such level, but the journal row it resets belongs to the library. So the gatekeeper
     * checks who is asking and the owner does the writing.
     *
     * The row is judged where it is written, not here: a page that read "failed" and then
     * asked would be judging a row another process is free to change in between. Carried by
     * {@see DeliveryRetrySignalData}, which brings the waiting admin along.
     */
    public const string HILOS_DELIVERY_RETRY = 'hilos_delivery_retry';

    /**
     * Notifications library → deliveries page: your retry is done, or it is refused (HIL-771).
     *
     * The way back for {@see self::HILOS_DELIVERY_RETRY} and only for it. The page deferred
     * its own ack when it handed the work over, so this frame is what finally answers the
     * admin - success, or the sentence saying why the row could not be re-queued. Carried by
     * {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_DELIVERY_RETRY_DONE = 'hilos_delivery_retry_done';

    // ── Hilos settings: gatekeeper screens ↔ settings library routes (agent signals) ──

    /**
     * A settings gatekeeper → the settings library: put this value under this key (HIL-946).
     *
     * The four asks below are cut by kind of write and not by the button that made them: the
     * eight calls the screens used to make come down to four things a settings row can have
     * done to it. This one is the whole of "a cataloged key now stands at this value" - the add
     * and the edit of the settings screen, switching a communications channel on, and filling a
     * field of one - because the settings table answers all four the same idempotent way.
     *
     * The names of the browser actions behind them are untouched and do not collide with these:
     * those are what a client presses, these are what one process asks another. Carried by
     * {@see SettingWriteSignalData}.
     */
    public const string HILOS_SETTING_WRITE = 'hilos_setting_write';

    /**
     * A settings gatekeeper → the settings library: put this key back to its default (HIL-946).
     *
     * Undoing an override, whichever screen spells it: the reset of the general settings screen
     * and the reset of one field of a communications channel. Whether the key is cataloged at
     * all - an orphan has no default to return to - is judged where the row is written. Carried
     * by {@see SettingResetSignalData}.
     */
    public const string HILOS_SETTING_RESET = 'hilos_setting_reset';

    /**
     * The settings screen → the settings library: drop this orphan row (HIL-946).
     *
     * Apart from the reset rather than a flag inside it, because only an orphan is ever deleted
     * and only a cataloged key is ever reset; the settings table refuses each in the other's
     * place. Carried by {@see SettingDeleteSignalData}.
     */
    public const string HILOS_SETTING_DELETE = 'hilos_setting_delete';

    /**
     * A presets section → the settings library: apply this preset of this group (HIL-946).
     *
     * The whole operation crosses, its checking included: the resolver judges every value of
     * the preset before writing the first, and a preset half applied is the one state its
     * screen cannot explain. The group travels as the class name of its provider, which is how
     * the section already holds it. Carried by {@see SettingPresetApplySignalData}.
     */
    public const string HILOS_SETTING_PRESET_APPLY = 'hilos_setting_preset_apply';

    /**
     * The settings library → the general settings screen: your write is done, or refused
     * (HIL-946).
     *
     * One of three ways back, and there are three rather than one because the map of page-owned
     * signals holds one entry per name: two screens under one name would overwrite each other
     * without a word, and the topology validator catches that between agents but not between
     * two pages. Carried by {@see HandoverAnswerSignalData}, as all three are.
     */
    public const string HILOS_SETTING_WRITE_DONE = 'hilos_setting_write_done';

    /**
     * The settings library → the communications channel screen: your write is done, or refused
     * (HIL-946).
     *
     * The channel screen's own way back. It writes settings through the same four asks, and it
     * needs a name of its own for the same reason the general screen does. Carried by
     * {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_CHANNEL_SETTING_WRITE_DONE = 'hilos_channel_setting_write_done';

    /**
     * Settings library → OAuth providers page: the return-address write it forwarded is done (HIL-286).
     *
     * A name of that page's own for the same reason as {@see HILOS_CHANNEL_SETTING_WRITE_DONE}:
     * the map of page-owned signals holds one entry per name.
     */
    public const string HILOS_OAUTH_REDIRECT_WRITE_DONE = 'hilos_oauth_redirect_write_done';

    /**
     * Settings library → sign-in methods page: the method-list write it forwarded is done (HIL-427).
     *
     * A name of that page's own for the same reason as {@see HILOS_CHANNEL_SETTING_WRITE_DONE}.
     */
    public const string HILOS_SIGN_IN_METHODS_WRITE_DONE = 'hilos_sign_in_methods_write_done';

    /**
     * Settings library → the two-factor admin page: the setting write it asked for is done (HIL-494).
     *
     * Carried by {@see HandoverAnswerSignalData}, like the other admin screens' write answers.
     */
    public const string HILOS_SECOND_FACTOR_SETTING_WRITE_DONE = 'hilos_second_factor_setting_write_done';

    /**
     * The settings library → the log modes screen: your preset is applied, or refused (HIL-946).
     *
     * The way back for the one presets section that exists today. The name belongs to the
     * section and not to the base class of presets pages: the base declares the contract that a
     * section must name one, and reads it off the subclass. Carried by
     * {@see HandoverAnswerSignalData}.
     */
    public const string HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE = 'hilos_logs_settings_preset_apply_done';

    // ── Hilos backup admin: page → monopoly BackupAgent routes (agent signals) ──
    /** Page → BackupAgent: run a backup in the carried scope (guarded create path). */
    public const string BACKUP_AGENT_CREATE = 'backup_agent_create';

    /** Page → BackupAgent: delete the carried backup id (shared delete path). */
    public const string BACKUP_AGENT_DELETE = 'backup_agent_delete';

    /** BackupAgent → backup page: how one delete of a bulk run ended, for the run it names. */
    public const string HILOS_BACKUP_DELETE_DONE = 'hilos_backup_delete_done';

    /** Page → BackupAgent: set the carried backup id's keep pin (sidecar rewrite). */
    public const string BACKUP_AGENT_SET_KEEP = 'backup_agent_set_keep';

    /**
     * Page → BackupAgent: restore the carried backup id (the hot restore path, HIL-276).
     *
     * The second entrance to the path the `backup:restore` CLI already takes over the command
     * channel. The page carries its own verdict along, and the agent re-checks it as a
     * backstop: between the page's answer and this signal the agent may have taken work on.
     */
    public const string BACKUP_AGENT_RESTORE = 'backup_agent_restore';

    /**
     * Page → BackupAgent: end the verification window this node stands in (HIL-676).
     *
     * The page has already checked the runtime row and answered the browser, so this frame is
     * a request to act, not to decide; the agent re-checks the row all the same, because
     * between the page's answer and this signal a terminal may have opened the system already.
     * The accept key rides along only so the agent can name whose click it is carrying out or
     * refusing in its log. Carried by {@see BackupReopenSignalData}.
     */
    public const string BACKUP_AGENT_REOPEN = 'backup_agent_reopen';

    /**
     * Sessions library → BackupAgent: the batch of deferred logins is mine now (HIL-846).
     *
     * The receipt for {@see self::HILOS_SESSION_CARRYOVER_HANDOVER}. It means "I have it", not
     * "all of it worked": a login that could not be re-created is lost on purpose and counted,
     * and holding the batch over it would only offer it again forever. On it the holder removes
     * exactly that batch's file and lets go of the freeze-lift it parked until these logins were
     * back - the wait lives with the backup agent that ran the restore, not with any master
     * (RestoreReleaseGate, HIL-969). Carried by {@see DeferredSessionsCarriedSignalData}.
     */
    public const string BACKUP_AGENT_SESSIONS_CARRIED = 'backup_agent_sessions_carried';

    /**
     * Notifications library → BackupAgent: the batch of deferred notices is mine now (HIL-846).
     *
     * The receipt for {@see self::HILOS_NOTIFICATION_HANDOVER}, meaning what
     * {@see self::BACKUP_AGENT_SESSIONS_CARRIED} means; the holder removes that batch's file and
     * owes no master anything for it. Carried by {@see DeferredNoticesSentSignalData}.
     */
    public const string BACKUP_AGENT_NOTICES_SENT = 'backup_agent_notices_sent';

    // ── Hilos logs admin: viewer page → the node that owns the files (agent signal) ──
    /**
     * Viewer page → {@see LogStoreAgent} on the named node: read this page of lines (HIL-757).
     *
     * The cross-node half of {@see self::LOGS_READ_LINES}. The owner declares
     * {@see AgentSignalConfigKey::NODE_FIELD} on it, so the id in the payload is what the router
     * addresses by; an empty id is this node and the frame never leaves it.
     *
     * It carries the accept key, the action name and the request id as well as the request,
     * because the page deferred its own ack: the owner is the last step of this action and
     * answers the browser itself, over a socket another node may be holding. Carried by
     * {@see LogsReadLinesSignalData}.
     */
    public const string LOGS_AGENT_READ_LINES = 'logs_agent_read_lines';

    /**
     * Viewer page → {@see LogStoreAgent} on the named node: follow this file from its end (HIL-389).
     *
     * The cross-node half of {@see self::LOGS_FOLLOW_START}, routed by the same
     * {@see AgentSignalConfigKey::NODE_FIELD} the read is routed by. It carries the accept key, the
     * action name and the request id along with the request: the page deferred its own ack, and the
     * owner both answers the first page and goes on sending the file's growth to that same socket.
     * Carried by {@see LogsFollowStartSignalData}.
     */
    public const string LOGS_AGENT_FOLLOW_START = 'logs_agent_follow_start';

    /**
     * Viewer page → {@see LogStoreAgent} on the remembered node: drop this connection's follow.
     *
     * Sent when the viewer asks, and when it leaves the page or its socket closes - the page hears
     * both as one unsubscribe. Addressed to the node the page recorded at the start rather than to
     * the one the browser names, so a viewer that has switched nodes still releases the reader it
     * left behind. Carried by {@see LogsFollowStopSignalData}.
     */
    public const string LOGS_AGENT_FOLLOW_STOP = 'logs_agent_follow_stop';

    /**
     * Rotations page → {@see LogStoreAgent} on the named node: write this batch's takeout marker (HIL-483).
     *
     * The cross-node half of {@see self::LOGS_TAKEOUT_CONFIRM}, routed by the same
     * {@see AgentSignalConfigKey::NODE_FIELD} the read is routed by. The owner re-judges the batch
     * before it writes: the page decided the button was worth offering, this decides whether the
     * directory still deserves the marker. It carries the accept key, the action name and the
     * request id, because the page deferred its own ack and the owner answers the browser itself.
     * Carried by {@see LogsTakeoutConfirmSignalData}.
     */
    public const string LOGS_AGENT_TAKEOUT_CONFIRM = 'logs_agent_takeout_confirm';

    /**
     * Rotations page → {@see LogStoreAgent} on the named node: remove this batch's takeout marker (HIL-759).
     *
     * The cross-node half of {@see self::LOGS_TAKEOUT_UNDO}, routed by the same
     * {@see AgentSignalConfigKey::NODE_FIELD} the confirmation is routed by. The owner asks one
     * question before it removes: is the batch still on this node — because a batch the pruner has
     * already taken has nothing to return to the list. It carries the accept key, the action name
     * and the request id, because the page deferred its own ack and the owner answers the browser
     * itself. Carried by {@see LogsTakeoutUndoSignalData}.
     */
    public const string LOGS_AGENT_TAKEOUT_UNDO = 'logs_agent_takeout_undo';

    // ── Mail subsystem: facade → sharded hilos_mail agent pool (agent signal) ──
    /**
     * {@see NotificationDispatcher} → mail agent pool: deliver one email notification.
     *
     * The notification-delivery intake of the email channel ({@see MailDeliveryChannel}),
     * carried by {@see NotificationDeliverSignalData}; INDEX_FIELD is its
     * `shardKey`, derived from the recipient address so it co-locates with the raw-send intake on one pool
     * instance. The raw-send intake uses {@see HILOS_MAIL_SEND} instead.
     */
    public const string HILOS_MAIL_DELIVER = 'hilos_mail_deliver';

    /**
     * {@see HilosMailer::send()} → mail agent pool: raw-send one message.
     *
     * The raw-send intake (Auth codes, magic links) carried by
     * {@see MailSendSignalData}; INDEX_FIELD is its `shardKey`, so the
     * signal routes to one pool instance by recipient address. The notification-delivery
     * intake uses the mail channel's own deliver signal instead.
     */
    public const string HILOS_MAIL_SEND = 'hilos_mail_send';

    // ── SMS subsystem: facade → sharded hilos_sms agent pool (agent signal) ──
    /**
     * {@see NotificationDispatcher} → sms agent pool: deliver one SMS notification.
     *
     * The notification-delivery intake of the SMS channel ({@see SmsDeliveryChannel}),
     * carried by {@see NotificationDeliverSignalData}; INDEX_FIELD is its
     * `shardKey`, derived from the recipient number so it co-locates with the raw-send intake on one pool
     * instance. The raw-send intake uses {@see HILOS_SMS_SEND} instead.
     */
    public const string HILOS_SMS_DELIVER = 'hilos_sms_deliver';

    /**
     * {@see HilosSmsSender::send()} → sms agent pool: raw-send one message.
     *
     * The raw-send intake (Auth login/add codes) carried by
     * {@see SmsSendSignalData}; INDEX_FIELD is its `shardKey`, so the
     * signal routes to one pool instance by recipient number. The notification-delivery
     * intake uses the SMS channel's own deliver signal instead.
     */
    public const string HILOS_SMS_SEND = 'hilos_sms_send';

    // ── Push subsystem: facade → sharded hilos_push agent pool (agent signal) ──
    /**
     * {@see NotificationDispatcher} → push agent pool: deliver one push notification.
     *
     * The notification-delivery intake of the web-push channel ({@see PushDeliveryChannel}),
     * carried by {@see NotificationDeliverSignalData}; INDEX_FIELD is its
     * `shardKey`, derived from the recipient id so a recipient's deliveries stay on one pool instance. Push has
     * no raw-send intake, so this is the channel's only signal.
     */
    public const string HILOS_PUSH_DELIVER = 'hilos_push_deliver';

    /** Push delivery agent → notifications library: endpoints reported gone by the service. */
    public const string HILOS_PUSH_SUBSCRIPTIONS_GONE = 'hilos_push_subscriptions_gone';

    /**
     * Session holder → notifications library: an account was erased, forget its person (HIL-302).
     *
     * Sent once the erasure is committed; the library deletes the person's notifications with
     * their journal, their channel preferences and their push subscriptions. Best-effort: a lost
     * frame leaves rows of nobody, with no retry. Carried by {@see NotificationForgetUserSignalData}.
     */
    public const string HILOS_NOTIFICATION_FORGET_USER = 'hilos_notification_forget_user';

    // ── Hilos logs admin: the node that owns the files → the cluster log aggregator (agent signal) ──
    /**
     * {@see LogStoreAgent} on one node → the cluster-wide {@see LogAggregatorAgent}: my store, whole (HIL-755).
     *
     * Sent by the owner of a log directory on its own tick, unasked, and carrying the node's index
     * in FULL rather than what changed since the last frame ({@see NodeLogIndexSignalData}). That is
     * what makes a lost frame, a restarted aggregator and an aggregator moved by policy repair
     * themselves: the next ordinary frame is already the whole picture, so there is no protocol for
     * asking anybody to send everything again.
     *
     * One direction only. The aggregator never answers and never opens a log directory of its own;
     * it files the frame under that node's slot and nothing else.
     */
    public const string LOGS_NODE_INDEX_REPORT = 'logs_node_index_report';

    // ── Hilos logs admin: the logs pages agent → the cluster log aggregator (agent signal) ──
    /**
     * {@see AbstractHilosLogsAgent} → the cluster-wide {@see LogAggregatorAgent}: this many people
     * are watching (HIL-756).
     *
     * The whole of the subscription protocol, carried by {@see LogsIndexWatchSignalData}. There is
     * no pair of subscribe and unsubscribe frames: the number of viewers both renews the lease and
     * cancels it by being zero, so a subscriber that dies mid-conversation is forgotten by the
     * lease running out rather than by a farewell nobody was there to send.
     *
     * Repeated on the sender's own tick, which is what makes a restarted aggregator and one moved
     * by the placement policy repair themselves: the new instance starts with an empty register and
     * the next ordinary claim puts the sender back in it.
     */
    public const string LOGS_INDEX_WATCH = 'logs_index_watch';

    // ── Hilos logs admin: the cluster log aggregator → the logs pages agent (agent signal) ──
    /**
     * {@see LogAggregatorAgent} → {@see AbstractHilosLogsAgent}: the nodes that changed, whole
     * (HIL-756).
     *
     * The answer to a claim of interest, carried by {@see ClusterLogIndexPortionSignalData}: a full
     * snapshot of every slot on the first non-zero claim from a subscriber, and afterwards only the
     * slots that changed since that subscriber was last written to, no more often than the
     * aggregator's coalescing window. Nothing goes out at all while nobody claims to be watching.
     *
     * Each slot travels whole, never as a line-level difference - the same decision the frames
     * beneath it are built on (HIL-754/755), and what makes a lost frame repair itself with the
     * next one instead of needing a protocol for asking again.
     */
    public const string LOGS_CLUSTER_INDEX_PORTION = 'logs_cluster_index_portion';
}
