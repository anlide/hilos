<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatSignalConstants - Chat signal name constants.
 *
 * Defines signal name constants used in chat demo.
 */
final class ChatSignalConstants
{
    /** @var string Start signal name */
    public const string START = 'start';

    /** @var string Message signal name */
    public const string MESSAGE = 'message';

    /** @var string File signal name */
    public const string FILE = 'file';

    /** @var string Client → server: start binary file upload (metadata only) */
    public const string FILE_UPLOAD_INIT = 'file_upload_init';

    /** @var string Client → server: delete one completed attachment draft */
    public const string ATTACHMENT_DRAFT_DELETE = 'attachment_draft_delete';

    /** @var string Client → server: look an identifier up while it is typed (public, anonymous-reachable) */
    public const string DETECT_IDENTIFIER = 'detect_identifier';

    /** @var string Client → server: email+password login (public, anonymous-reachable) */
    public const string LOGIN = 'login';

    /** @var string Client → server: email+password registration (public, anonymous-reachable) */
    public const string REGISTER = 'register';

    /** @var string Client → server: request a password-reset code (public, anonymous-reachable) */
    public const string REQUEST_PASSWORD_RESET = 'request_password_reset';

    /** @var string Client → server: submit a password-reset code, without the new password (public, anonymous-reachable) */
    public const string CONFIRM_PASSWORD_RESET = 'confirm_password_reset';

    /** @var string Client → server: save the new password of an accepted recovery (public, anonymous-reachable, HIL-416) */
    public const string COMPLETE_PASSWORD_RESET = 'complete_password_reset';

    /** @var string Client → server: request an SMS one-time login code for a phone (public, anonymous-reachable) */
    public const string REQUEST_SMS_CODE = 'request_sms_code';

    /** @var string Client → server: submit an SMS one-time login code for a phone (public, anonymous-reachable) */
    public const string CONFIRM_SMS_CODE = 'confirm_sms_code';

    /** @var string Client → server: request an email magic-link sign-in token (public, anonymous-reachable) */
    public const string REQUEST_MAGIC_LINK = 'request_magic_link';

    /** @var string Client → server: submit an email magic-link sign-in token (public, anonymous-reachable) */
    public const string CONFIRM_MAGIC_LINK = 'confirm_magic_link';

    /** @var string Client → server: resend the confirmation code of a pending registration (public, anonymous-reachable, HIL-415) */
    public const string REQUEST_REGISTER_CONFIRM = 'request_register_confirm';

    /** @var string Client → server: submit the confirmation code that creates the reserved account (public, anonymous-reachable, HIL-415) */
    public const string CONFIRM_REGISTER = 'confirm_register';

    /** @var string Client → server: begin an OAuth login by minting the provider authorize URL (public, anonymous-reachable) */
    public const string OAUTH_START = 'oauth_start';

    /** @var string Client → server: hand back the OAuth provider code+state after the redirect (public, anonymous-reachable) */
    public const string OAUTH_CALLBACK = 'oauth_callback';

    /** @var string Client → server: redeem an OAuth link token after re-auth to link the account (authenticated, HIL-282) */
    public const string LINK_OAUTH_AFTER_REAUTH = 'link_oauth_after_reauth';

    /** @var string Client → server: begin linking an OAuth provider to the signed-in account (authenticated, HIL-401) */
    public const string LINK_OAUTH_START = 'link_oauth_start';

    /** @var string Client → server: request WebAuthn registration options for the signed-in user (authenticated, HIL-284) */
    public const string PASSKEY_REGISTER_OPTIONS = 'passkey_register_options';

    /** @var string Client → server: submit a WebAuthn registration attestation to store a new passkey (authenticated, HIL-284) */
    public const string PASSKEY_REGISTER_CONFIRM = 'passkey_register_confirm';

    /** @var string Client → server: submit a WebAuthn login assertion to sign in (public, anonymous-reachable, HIL-284) */
    public const string PASSKEY_LOGIN_CONFIRM = 'passkey_login_confirm';

    /**
     * @var string Client → server: request WebAuthn discoverable (usernameless) login options — no
     *     email, empty allowCredentials (public, anonymous-reachable, HIL-400)
     */
    public const string PASSKEY_DISCOVERABLE_LOGIN_OPTIONS = 'passkey_discoverable_login_options';

    /** @var string Client → agent: revert the authenticated session to anonymous (shell logout, page-independent) */
    public const string LOGOUT = 'logout';

    /**
     * @var string Client → agent (page-independent): impersonating session reverts to its admin
     *     (browser name; the CLI command is ChatCommandConstants::IMPERSONATE_STOP)
     */
    public const string IMPERSONATE_STOP = 'impersonate_stop';

    /** @var string Rename signal name */
    public const string RENAME = 'rename';

    /** @var string Profile unlink-identity action name (HIL-377) */
    public const string UNLINK_IDENTITY = 'unlink_identity';

    /** @var string Client → server: add or change the current user's password from the profile (HIL-402) */
    public const string SET_PASSWORD = 'set_password';

    /** @var string Server → client: the current user's password was added/changed (HIL-402) */
    public const string PASSWORD_UPDATED = 'password_updated';

    /** @var string Client → server: request an SMS code to add a phone identity to the signed-in user (authenticated, HIL-403) */
    public const string ADD_SMS_REQUEST = 'profile_add_sms_request';

    /** @var string Client → server: submit the SMS code to add the phone identity to the signed-in user (authenticated, HIL-403) */
    public const string ADD_SMS_CONFIRM = 'profile_add_sms_confirm';

    /** @var string Client → server: request an email code to add a password to a signed-in user with no verified email (authenticated, HIL-406) */
    public const string ADD_PASSWORD_REQUEST = 'profile_add_password_request';

    /** @var string Client → server: submit the email code and new password to add a password to the signed-in user (authenticated, HIL-406) */
    public const string ADD_PASSWORD_CONFIRM = 'profile_add_password_confirm';

    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string Server → client: a parked sign-in surface moves because the identifier it waits on resolved (HIL-415) */
    public const string AUTH_CONVERGE = 'auth_converge';

    /** @var string Server → client: WebAuthn publicKey options + signed challenge for a passkey ceremony (HIL-284) */
    public const string PASSKEY_OPTIONS = 'passkey_options';

    /** @var string Subscription page main signal name */
    public const string SUBSCRIPTION_PAGE_MAIN = 'subscription_page_main';

    /** @var string Subscription page user signal name */
    public const string SUBSCRIPTION_PAGE_USER = 'subscription_page_user';

    /** @var string Subscription page bot signal name */
    public const string SUBSCRIPTION_PAGE_BOT = 'subscription_page_bot';

    /** @var string Subscription page moderator signal name */
    public const string SUBSCRIPTION_PAGE_MODERATOR = 'subscription_page_moderator';

    /** @var string Subscription page admin signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN = 'subscription_page_admin';

    /** @var string Subscription page admin users signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_USERS = 'subscription_page_admin_users';

    /** @var string Subscription page admin moderator signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_MODERATOR = 'subscription_page_admin_moderator';

    /** @var string Subscription page admin bots signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_BOTS = 'subscription_page_admin_bots';

    // ── Agent-to-agent signals ───────────────────────────────────────────
    /** @var string ModeratorAgent → ChatAgent: message moderation result */
    public const string MODERATION_RESULT = 'moderation_result';

    /** @var string ModeratorAgent → ChatAgent: user-initiated rename moderation result */
    public const string RENAME_MODERATION_RESULT = 'rename_moderation_result';

    // ── Bot agent lifecycle ─────────────────────────────────────────────
    /** @var string LibraryAgent → BotAgent: start (sending to agent triggers framework start if not running) */
    public const string BOT_AGENT_START = 'bot_agent_start';

    /** @var string BotAgent → ChatAgent: publish generated bot message */
    public const string BOT_MESSAGE = 'bot_message';

    /** @var string OAuthAgent → ChatAgent: bind the resolved user to the initiating session (success login) */
    public const string OAUTH_BIND_SESSION = 'oauth_bind_session';

    /** @var string Hilos user page (HILOS_INDEX agent) → ChatAgent: run an admin account merge on the session-owning agent (HIL-378) */
    public const string ACCOUNT_MERGE_REQUEST = 'account_merge_request';

    // ── Table actions (client → server) ──────────────────────────────────
    /** @var string User update signal name */
    public const string USER_UPDATE = 'user_update';

    /** @var string Client → server (Hilos user page-action): admin merges the loser account into this survivor (HIL-378) */
    public const string ACCOUNT_MERGE = 'account_merge';

    /** @var string ChatAgent → initiator: account merge succeeded (ack for ACCOUNT_MERGE) */
    public const string ACCOUNT_MERGE_SUCCESS = 'account_merge_success';

    /** @var string ChatAgent → initiator: account merge failed (ack for ACCOUNT_MERGE) */
    public const string ACCOUNT_MERGE_FAIL = 'account_merge_fail';

    /**
     * @var string Client → server (admin users page-action): admin starts impersonating a target user
     *     (browser name; the CLI command is ChatCommandConstants::IMPERSONATE_START)
     */
    public const string IMPERSONATE_START = 'impersonate_start';

    /** @var string Bot create signal name */
    public const string BOT_CREATE = 'bot_create';

    /** @var string Bot update signal name */
    public const string BOT_UPDATE = 'bot_update';

    /** @var string Bot delete signal name */
    public const string BOT_DELETE = 'bot_delete';

    /** @var string Moderator piece create signal name */
    public const string MODERATOR_PIECE_CREATE = 'moderator_piece_create';

    /** @var string Moderator piece update signal name */
    public const string MODERATOR_PIECE_UPDATE = 'moderator_piece_update';

    /** @var string Moderator piece delete signal name */
    public const string MODERATOR_PIECE_DELETE = 'moderator_piece_delete';

    /** @var string Guardian agent run start action name */
    public const string GUARDIAN_AGENT_RUN_START = 'guardian_agent_run_start';

    /** @var string Guardian agent run stop action name */
    public const string GUARDIAN_AGENT_RUN_STOP = 'guardian_agent_run_stop';

    // ── Table signals (server → client) ──────────────────────────────────
    /** @var string Server responds with fresh table data */
    public const string TABLE_DATA = 'table_data';

    /** @var string Server sends a single immediate table mutation */
    public const string TABLE_MUTATION = 'table_mutation';

    /** @var string Server broadcasts a single pending table mutation */
    public const string TABLE_MUTATION_PENDING = 'table_mutation_pending';

    /** @var string Server sends a table action error to the originating client */
    public const string TABLE_ACTION_ERROR = 'table_action_error';
}
