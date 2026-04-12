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

    /** @var string Client → server: dismiss file moderation rejected UI */
    public const string FILE_MODERATION_DISMISS = 'file_moderation_dismiss';

    /** @var string Server → client: upload session ready, client may send binary */
    public const string FILE_UPLOAD_READY = 'file_upload_ready';

    /** @var string Server → client: init rejected (limits, duplicate name, etc.) */
    public const string FILE_UPLOAD_REJECTED = 'file_upload_rejected';

    /** @var string Server → client: previous upload cancelled (new init while in progress) */
    public const string FILE_UPLOAD_ABORTED = 'file_upload_aborted';

    /** @var string Server → client: all bytes received, moderation started */
    public const string FILE_UPLOAD_COMPLETE = 'file_upload_complete';

    /** @var string Server → client: binary protocol error, session cleared */
    public const string FILE_UPLOAD_INVALID = 'file_upload_invalid';

    /** @var string Server → client: file attachment UI state (private to user); moderating/rejected only */
    public const string FILE_MODERATION_STATE_UPDATE = 'file_moderation_state_update';

    /** @var string Server → client: binary upload progress (throttled); separate from moderation */
    public const string FILE_UPLOAD_PROGRESS_UPDATE = 'file_upload_progress_update';

    /** @var string ChatAgent → ModeratorAgent: moderate uploaded file (synthetic description) */
    public const string MODERATE_FILE_REQUEST = 'moderate_file_request';

    /** @var string ModeratorAgent → ChatAgent: file moderation result */
    public const string MODERATION_FILE_RESULT = 'moderation_file_result';

    /** @var string Rename signal name */
    public const string RENAME = 'rename';

    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string New event signal name */
    public const string NEW_EVENT = 'new_event';

    /** @var string Subscription page main signal name */
    public const string SUBSCRIPTION_PAGE_MAIN = 'subscription_page_main';

    /** @var string Subscription page profile signal name */
    public const string SUBSCRIPTION_PAGE_PROFILE = 'subscription_page_profile';

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

    /** @var string Subscription page Hilos settings signal name */
    public const string SUBSCRIPTION_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings';

    /** @var string Moderation state signal name */
    public const string MODERATION_STATE = 'moderation_state';

    /** @var string Server → client: private update of current user's moderation state (sent to user's connections only) */
    public const string MODERATION_STATE_UPDATE = 'moderation_state_update';

    // ── Agent-to-agent signals ───────────────────────────────────────────
    /** @var string ChatAgent → ModeratorAgent: request message moderation */
    public const string MODERATE_REQUEST = 'moderate_request';

    /** @var string ModeratorAgent → ChatAgent: moderation result */
    public const string MODERATION_RESULT = 'moderation_result';

    // ── Bot agent lifecycle ─────────────────────────────────────────────
    /** @var string ChatAgent → BotAgent: start (sending to agent triggers framework start if not running) */
    public const string BOT_AGENT_START = 'bot_agent_start';

    /** @var string BotAgent → ModeratorAgent: moderate bot message before publishing */
    public const string MODERATE_BOT_REQUEST = 'moderate_bot_request';

    /** @var string ModeratorAgent → ChatAgent: bot message moderation result */
    public const string MODERATION_BOT_RESULT = 'moderation_bot_result';

    /** @var string BotAgent → ChatAgent → frontend: bot joined the chat */
    public const string BOT_JOINED = 'bot_joined';

    /** @var string BotAgent → ChatAgent → frontend: bot left the chat */
    public const string BOT_LEFT = 'bot_left';

    /** @var string Server → frontend: bot data updated (e.g. after admin edit) */
    public const string BOT_UPDATED = 'bot_updated';

    // ── Table actions (client → server) ──────────────────────────────────
    /** @var string Table refresh signal name */
    public const string TABLE_REFRESH = 'table_refresh';

    /** @var string User update signal name */
    public const string USER_UPDATE = 'user_update';

    /** @var string Hilos user detail: update display name (handled on HILOS_USER page) */
    public const string HILOS_USER_UPDATE = 'hilos_user_update';

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

    /** @var string Setting add signal name */
    public const string SETTING_ADD = 'setting_add';

    /** @var string Setting update signal name */
    public const string SETTING_UPDATE = 'setting_update';

    /** @var string Setting delete signal name */
    public const string SETTING_DELETE = 'setting_delete';

    /** @var string Guardian agent run start action name */
    public const string GUARDIAN_AGENT_RUN_START = 'guardian_agent_run_start';

    /** @var string Guardian agent run stop action name */
    public const string GUARDIAN_AGENT_RUN_STOP = 'guardian_agent_run_stop';

    // ── Table signals (server → client) ──────────────────────────────────
    /** @var string Server responds with fresh table data */
    public const string TABLE_DATA = 'table_data';

    /** @var string Server broadcasts a single table mutation */
    public const string TABLE_MUTATION = 'table_mutation';

    /** @var string Server sends a table action error to the originating client */
    public const string TABLE_ACTION_ERROR = 'table_action_error';
}
