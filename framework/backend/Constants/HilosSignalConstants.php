<?php

declare(strict_types=1);

namespace Hilos\Constants;

use Hilos\Auth\Code\DTO\AuthCodeResultSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryGrantedSignalData;
use Hilos\Auth\Library\DTO\AuthRecoveryWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationAbandonedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Notification\DTO\DeliveryRetryDoneSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Users\DTO\AccountMergeResultSignalData;
use Hilos\Users\DTO\AdminRenameDoneSignalData;
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
     * ack its own surface has always listened for. Carried by {@see AdminRenameDoneSignalData}.
     */
    public const string HILOS_USER_ADMIN_RENAME_DONE = 'hilos_user_admin_rename_done';

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

    /**
     * Client → server: restore the named stored backup on the HILOS_BACKUP page (HIL-276).
     *
     * The destructive one, and the only page action the environment can withhold: the
     * button exists everywhere but production, where the page hands out the CLI command
     * instead. The refusal is recomputed on this action all the same — a client is not the
     * source of truth about where it runs.
     */
    public const string BACKUP_RESTORE = 'backup_restore';

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
     * Client → server: "not that address?" on a code screen (public, anonymous-reachable, HIL-486).
     *
     * Ends the registration this SESSION was waiting on, in every tab of it at once.
     * It frees no address: the hold belongs to the identifier and other sessions may
     * still be waiting on the same one, so a person walking away from a code screen
     * cannot cancel somebody else's registration.
     */
    public const string HILOS_ABANDON_REGISTRATION = 'hilos_abandon_registration';

    /** Client → server: begin an OAuth login by minting the provider authorize URL (public, anonymous-reachable). */
    public const string HILOS_OAUTH_START = 'hilos_oauth_start';

    /** Client → server: hand back the OAuth provider code+state after the redirect (public, anonymous-reachable). */
    public const string HILOS_OAUTH_CALLBACK = 'hilos_oauth_callback';

    /**
     * Client → agent (page-independent): the person has read the success ack an auth
     * flow left on this session, so clear it from every socket of the session (HIL-422).
     */
    public const string HILOS_DISMISS_SESSION_ACK = 'hilos_dismiss_session_ack';

    /**
     * Client → sessions library (page-independent): revert this session to anonymous.
     *
     * Framework-owned since HIL-710, where the sign-out control stopped addressing a project
     * agent: the session is the library's, and the control sits in the app shell of every
     * project rather than on a page of one. The name is the framework's for the same reason
     * the action is - a project that kept its own would be naming a door it no longer holds.
     */
    public const string HILOS_LOGOUT = 'hilos_logout';

    /**
     * Client → sessions library (page-independent): make this admin session act as another
     * user (HIL-729).
     *
     * Page-independent although its only control today sits on an admin table: what it
     * writes is a session, and the session is the library's. Naming it on a page would tie
     * the takeover to the page that happens to offer it, and the very next frame moves the
     * person off that page - the effective user becomes the non-admin target, so an admin
     * page is no longer theirs to be on.
     *
     * Whether this session MAY is the project's answer, not the name's: the flag that says
     * "administrator" is a project field, and the library asks for it through a seam.
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
     * Users library → the session holder: this recovery is granted, move its tabs along.
     *
     * The recovery counterpart of {@see HILOS_AUTH_REGISTRATION_LANDED}: the code was
     * proved in the library, and the tabs sitting on the code screen belong to the holder.
     * Carried by {@see AuthRecoveryGrantedSignalData}.
     */
    public const string HILOS_AUTH_RECOVERY_GRANTED = 'hilos_auth_recovery_granted';

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
     * Users library → the session holder: this browser walked away from its registration.
     *
     * Drops the pending registration of the session and the waits standing on it. The
     * reservation is deliberately NOT released - it is what keeps a second person from
     * taking the identifier while the first one is still deciding.
     * Carried by {@see AuthRegistrationAbandonedSignalData}.
     */
    public const string HILOS_AUTH_REGISTRATION_ABANDONED = 'hilos_auth_registration_abandoned';

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
     * Code agent → the requesting connection: what became of the code request.
     *
     * Every outcome travels here, success included, which is what parts this from the
     * usual action ack: the person asking is a guest with no account and no session to
     * fan out to, so the accept key of their live socket is the only address there is
     * (the shape {@see HILOS_OAUTH_RESULT} established). The code screen opens on THIS
     * signal rather than on the click, so the channel it names is the channel the code
     * actually went over. Carried by {@see AuthCodeResultSignalData}.
     */
    public const string HILOS_AUTH_CODE_RESULT = 'hilos_auth_code_result';

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
     * Project agent → sessions library: fold this account into that one (HIL-729).
     *
     * The second way into the merge, beside {@see CliCommands::ACCOUNT_MERGE}, and the one a
     * browser reaches: an admin table submits it as a page action, and the page forwards it
     * here because the merge ends in the loser's live sessions being signed out - and those
     * sessions are the library's. Carried by {@see AccountMergeSignalData}.
     *
     * The accept key travels with it and comes back untouched on
     * {@see self::HILOS_ACCOUNT_MERGE_RESULT}: the person who asked is waiting on the page
     * they asked from, and the library that does the work cannot name a project's ack.
     */
    public const string HILOS_ACCOUNT_MERGE = 'hilos_account_merge';

    /**
     * Sessions library → project agent: this is what the merge did, say it out loud.
     *
     * The way back for {@see self::HILOS_ACCOUNT_MERGE} and only for it - an operator on the
     * command channel is answered where the work happened, on the parked socket. It carries
     * either what moved or why nothing did ({@see AccountMergeResultSignalData}), and the
     * project turns that into the ack its own surface waits for.
     */
    public const string HILOS_ACCOUNT_MERGE_RESULT = 'hilos_account_merge_result';

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
     * {@see DeliveryRetryDoneSignalData}.
     */
    public const string HILOS_DELIVERY_RETRY_DONE = 'hilos_delivery_retry_done';

    // ── Hilos backup admin: page → monopoly BackupAgent routes (agent signals) ──
    /** Page → BackupAgent: run a backup in the carried scope (guarded create path). */
    public const string BACKUP_AGENT_CREATE = 'backup_agent_create';

    /** Page → BackupAgent: delete the carried backup id (shared delete path). */
    public const string BACKUP_AGENT_DELETE = 'backup_agent_delete';

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
