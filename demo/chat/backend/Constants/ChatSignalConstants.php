<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatSignalConstants - Chat signal name constants
 *
 * Defines signal name constants used in chat demo.
 */
class ChatSignalConstants
{
    public const string START = 'start';
    public const string MESSAGE = 'message';
    public const string FILE = 'file';
    public const string RENAME = 'rename';
    public const string HANDSHAKE_RESPONSE = 'handshake_response';
    public const string NEW_EVENT = 'new_event';
    public const string SUBSCRIPTION_PAGE_MAIN = 'subscription_page_main';
    public const string SUBSCRIPTION_PAGE_PROFILE = 'subscription_page_profile';
    public const string SUBSCRIPTION_PAGE_USER = 'subscription_page_user';
    public const string SUBSCRIPTION_PAGE_BOT = 'subscription_page_bot';
    public const string SUBSCRIPTION_PAGE_MODERATOR = 'subscription_page_moderator';
    public const string SUBSCRIPTION_PAGE_ADMIN = 'subscription_page_admin';
    public const string SUBSCRIPTION_PAGE_ADMIN_USERS = 'subscription_page_admin_users';
    public const string SUBSCRIPTION_PAGE_ADMIN_MODERATOR = 'subscription_page_admin_moderator';
    public const string SUBSCRIPTION_PAGE_ADMIN_BOTS = 'subscription_page_admin_bots';
    public const string MODERATION_STATE = 'moderation_state';
    /** Server → client: private update of current user's moderation state (sent to user's connections only) */
    public const string MODERATION_STATE_UPDATE = 'moderation_state_update';

    // ── Agent-to-agent signals ───────────────────────────────────────────
    /** ChatAgent → ModeratorAgent: request message moderation */
    public const string MODERATE_REQUEST = 'moderate_request';
    /** ModeratorAgent → ChatAgent: moderation result */
    public const string MODERATION_RESULT = 'moderation_result';

    // ── Table actions (client → server) ──────────────────────────────────
    public const string TABLE_REFRESH = 'table_refresh';
    public const string USER_UPDATE = 'user_update';
    public const string BOT_CREATE = 'bot_create';
    public const string BOT_UPDATE = 'bot_update';
    public const string BOT_DELETE = 'bot_delete';
    public const string MODERATOR_PIECE_CREATE = 'moderator_piece_create';
    public const string MODERATOR_PIECE_UPDATE = 'moderator_piece_update';
    public const string MODERATOR_PIECE_DELETE = 'moderator_piece_delete';

    // ── Table signals (server → client) ──────────────────────────────────
    /** Server responds with fresh table data */
    public const string TABLE_DATA = 'table_data';

    /** Server broadcasts a single table mutation */
    public const string TABLE_MUTATION = 'table_mutation';

    /** Server sends a table action error to the originating client */
    public const string TABLE_ACTION_ERROR = 'table_action_error';
}
