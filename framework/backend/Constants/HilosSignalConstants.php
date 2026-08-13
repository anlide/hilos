<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;

/**
 * Signal names used by framework-level Hilos admin pages.
 */
final class HilosSignalConstants
{
    /** Subscription signal for Hilos dashboard page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DASHBOARD = 'subscription_page_hilos';

    /** Subscription signal for Hilos current-user profile page. */
    public const string SUBSCRIPTION_PAGE_HILOS_PROFILE = 'subscription_page_hilos_profile';

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

    /** Subscription signal for Hilos operations page. */
    public const string SUBSCRIPTION_PAGE_HILOS_OPERATIONS = 'subscription_page_hilos_operations';

    /** Subscription signal for Hilos users list. */
    public const string SUBSCRIPTION_PAGE_HILOS_USERS = 'subscription_page_hilos_users';

    /** Subscription signal for Hilos single user page. */
    public const string SUBSCRIPTION_PAGE_HILOS_USER = 'subscription_page_hilos_user';

    /** Subscription signal for the Hilos notification-center page (carries the snapshot). */
    public const string SUBSCRIPTION_PAGE_HILOS_NOTIFICATIONS = 'subscription_page_hilos_notifications';

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

    /** Server → initiator: hilos_user_update succeeded. */
    public const string HILOS_USER_UPDATE_SUCCESS = 'hilos_user_update_success';

    /** Server → initiator: hilos_user_update failed (carries a reason text). */
    public const string HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail';

    // ── Hilos settings admin: table mutation actions (client → server) ──
    /** Client → server: add a setting override on the HILOS_SETTINGS page. */
    public const string SETTING_ADD = 'setting_add';

    /** Client → server: update a setting value on the HILOS_SETTINGS page. */
    public const string SETTING_UPDATE = 'setting_update';

    /** Client → server: delete an orphan setting on the HILOS_SETTINGS page. */
    public const string SETTING_DELETE = 'setting_delete';

    // ── Hilos backup admin: list-page actions (client → server) ──
    /** Client → server: start a backup with a chosen scope on the HILOS_BACKUP page. */
    public const string BACKUP_CREATE = 'backup_create';

    /** Client → server: delete a stored backup on the HILOS_BACKUP page. */
    public const string BACKUP_DELETE = 'backup_delete';

    /** Client → server: toggle a stored backup's rotation pin on the HILOS_BACKUP page. */
    public const string BACKUP_SET_KEEP = 'backup_set_keep';

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

    // ── Hilos OAuth login: async agent → initiating browser (WS_USER) ──
    /**
     * OAuth agent → initiating connection: the async login exchange failed or timed out.
     *
     * The only OAuth outcome that needs its own signal: success rides the existing
     * session/currentUser fan-out (HIL-161), so the SPA callback surface resolves on
     * EITHER currentUser (login) OR this failure signal (see HIL-281 mechanism B).
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

    // ── Hilos backup admin: page → monopoly BackupAgent routes (agent signals) ──
    /** Page → BackupAgent: run a backup in the carried scope (guarded create path). */
    public const string BACKUP_AGENT_CREATE = 'backup_agent_create';

    /** Page → BackupAgent: delete the carried backup id (shared delete path). */
    public const string BACKUP_AGENT_DELETE = 'backup_agent_delete';

    /** Page → BackupAgent: set the carried backup id's keep pin (sidecar rewrite). */
    public const string BACKUP_AGENT_SET_KEEP = 'backup_agent_set_keep';

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
}
