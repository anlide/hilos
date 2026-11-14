<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;

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

    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string Subscription page main signal name */
    public const string SUBSCRIPTION_PAGE_MAIN = 'subscription_page_main';

    /** @var string Subscription page user signal name */
    public const string SUBSCRIPTION_PAGE_USER = 'subscription_page_user';

    /** @var string Subscription page bot signal name */
    public const string SUBSCRIPTION_PAGE_BOT = 'subscription_page_bot';

    /** @var string Subscription page admin signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN = 'subscription_page_admin';

    /** @var string Subscription page admin bots signal name */
    public const string SUBSCRIPTION_PAGE_ADMIN_BOTS = 'subscription_page_admin_bots';

    // ── Agent-to-agent signals ───────────────────────────────────────────
    /**
     * @var string Files library → ChatAgent: the attachments of an approved message are published
     *     into the registry, or why not - the answer to Hilos::$files->publishUploads() (HIL-144)
     */
    public const string ATTACHMENTS_PUBLISHED = 'chat_attachments_published';

    /**
     * @var string Users library → the person's agent: the rename's feed event exists, write the journal
     *     row's link to it (HIL-1896). Carried by {@see UserRenameFeedLinkSignalData}. Not answered.
     */
    public const string USER_RENAME_FEED_LINK = 'chat_user_rename_feed_link';

    /**
     * @var string ChatAgent → the person's agent: take every journal row of yours off its feed event
     *     before history is cleared, and answer (HIL-1897). Carried by {@see UserRenameFeedUnlinkSignalData}.
     */
    public const string USER_RENAME_FEED_UNLINK = 'chat_user_rename_feed_unlink';

    /**
     * @var string The person's agent → ChatAgent: the answer to USER_RENAME_FEED_UNLINK.
     *     Carried by {@see UserRenameFeedUnlinkedSignalData}.
     */
    public const string USER_RENAME_FEED_UNLINKED = 'chat_user_rename_feed_unlinked';

    /** Sessions library → ChatAgent: run an account-operation assignment. */
    public const string CHAT_ACCOUNT_OPERATION_STEP = 'chat_account_operation_step';

    // ── Bot agent lifecycle ─────────────────────────────────────────────
    /** @var string LibraryAgent → BotAgent: start (sending to agent triggers framework start if not running) */
    public const string BOT_AGENT_START = 'bot_agent_start';

    /**
     * @var string LibraryAgent → BotAgent: go to the model on the next free tick, past the delay, the chance,
     *     the pause after its own message and the topic (the test-only answer-now command)
     */
    public const string BOT_ANSWER_NOW = 'bot_answer_now';

    /** @var string BotAgent → ChatAgent: publish generated bot message */
    public const string BOT_MESSAGE = 'bot_message';

    // ── Table actions (client → server) ──────────────────────────────────
    /** @var string Bot create signal name */
    public const string BOT_CREATE = 'bot_create';

    /** @var string Bot update signal name */
    public const string BOT_UPDATE = 'bot_update';

    /** @var string Bot delete signal name */
    public const string BOT_DELETE = 'bot_delete';

    /** @var string Superfan agent run start action name */
    public const string SUPERFAN_AGENT_RUN_START = 'superfan_agent_run_start';

    /** @var string Superfan agent run stop action name */
    public const string SUPERFAN_AGENT_RUN_STOP = 'superfan_agent_run_stop';

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
