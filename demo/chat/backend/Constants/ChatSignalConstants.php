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

    /** @var string Client → server: email+password login (public, anonymous-reachable) */
    public const string LOGIN = 'login';

    /** @var string Client → agent: revert the authenticated session to anonymous (shell logout, page-independent) */
    public const string LOGOUT = 'logout';

    /** @var string Rename signal name */
    public const string RENAME = 'rename';

    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

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

    // ── Table actions (client → server) ──────────────────────────────────
    /** @var string User update signal name */
    public const string USER_UPDATE = 'user_update';

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
