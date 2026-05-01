<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Demo\Chat\Core\Router\ChatSignalMapper;

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

    /** @var string Server → client: upload session ready, client may send binary */
    public const string FILE_UPLOAD_READY = 'file_upload_ready';

    /** @var string Server → client: init rejected (limits, duplicate name, etc.) */
    public const string FILE_UPLOAD_REJECTED = 'file_upload_rejected';

    /** @var string Server → client: previous upload cancelled (new init while in progress) */
    public const string FILE_UPLOAD_ABORTED = 'file_upload_aborted';

    /** @var string Server → client: all bytes received, attachment draft created */
    public const string FILE_UPLOAD_COMPLETE = 'file_upload_complete';

    /** @var string Server → client: binary protocol error, session cleared */
    public const string FILE_UPLOAD_INVALID = 'file_upload_invalid';

    /** @var string Server → client: binary upload progress (throttled); separate from moderation */
    public const string FILE_UPLOAD_PROGRESS_UPDATE = 'file_upload_progress_update';

    /** @var string Server → client: full list of uploaded attachment drafts for this connection */
    public const string ATTACHMENT_DRAFTS_UPDATE = 'attachment_drafts_update';

    /** @var string Server → client: current outbound moderation state for text + attachments */
    public const string OUTBOUND_MODERATION_STATE_UPDATE = 'outbound_moderation_state_update';

    /** @var string Rename signal name */
    public const string RENAME = 'rename';

    /** @var string Rename success ack (sent only to initiator; envelope outcome='success') */
    public const string RENAME_SUCCESS = 'rename_success';

    /** @var string Rename failure ack (sent only to initiator; envelope outcome='fail') */
    public const string RENAME_FAIL = 'rename_fail';

    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string New event signal name */
    public const string NEW_EVENT = 'new_event';

    /** @var string Server → frontend: runtime-derived user presence changed */
    public const string USER_PRESENCE_UPDATE = 'user_presence_update';

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

    // ── Agent-to-agent signals ───────────────────────────────────────────
    /** @var string ChatAgent → ModeratorAgent: request message moderation */
    public const string MODERATE_REQUEST = 'moderate_request';

    /** @var string ModeratorAgent → ChatAgent: moderation result */
    public const string MODERATION_RESULT = 'moderation_result';

    // ── Bot agent lifecycle ─────────────────────────────────────────────
    /** @var string LibraryAgent → BotAgent: start (sending to agent triggers framework start if not running) */
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
    /** @var string User update signal name */
    public const string USER_UPDATE = 'user_update';

    /** @var string Hilos user detail: update display name (handled on HILOS_USER page) */
    public const string HILOS_USER_UPDATE = 'hilos_user_update';

    /** @var string Ack: hilos_user_update succeeded (sent to initiator) */
    public const string HILOS_USER_UPDATE_SUCCESS = 'hilos_user_update_success';

    /** @var string Ack: hilos_user_update failed (sent to initiator with reason text) */
    public const string HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail';

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

    // ── Emit events (worker → daemon → mapper → WebSocket) ───────────────
    /** @var string DB chat event created; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_EVENT_CREATED = 'emit_chat_event_created';

    /** @var string DB chat event stream replaced; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_EVENTS_REPLACED = 'emit_chat_events_replaced';

    /** @var string DB user row updated; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_USER_ROW_UPDATED = 'emit_chat_user_row_updated';

    /** @var string DB bot table row changed; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_BOT_ROW_CHANGED = 'emit_chat_bot_row_changed';

    /** @var string DB bot frontend state changed; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_BOT_FRONTEND_UPDATED = 'emit_chat_bot_frontend_updated';

    /** @var string DB moderator prompt piece row changed; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_MODERATOR_PROMPT_PIECE_ROW_CHANGED = 'emit_chat_moderator_prompt_piece_row_changed';

    /** @var string DB settings row changed; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_SETTING_ROW_CHANGED = 'emit_chat_setting_row_changed';

    /** @var string RT user presence changed; {@see ChatSignalMapper} page-scoped fan-out rules */
    public const string EMIT_CHAT_USER_PRESENCE_UPDATED = 'emit_chat_user_presence_updated';

    /** @var string RT bot agent lifecycle changed; {@see ChatSignalMapper} fan-out rules */
    public const string EMIT_CHAT_BOT_AGENT_STATUS_UPDATED = 'emit_chat_bot_agent_status_updated';

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
