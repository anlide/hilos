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

    /** Signal for table action response (load_page, refresh_snapshot) */
    public const string TABLE_UPDATE = 'table_update';
}
