<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Constants;

/**
 * PageConstants - Page constants for chat demo
 *
 * Defines page identifiers used for user subscriptions.
 */
class PageConstants
{
    /** @var string Chat main page */
    public const string MAIN = 'main';

    /** @var string User profile page */
    public const string PROFILE = 'profile';

    /** @var string User page */
    public const string USER = 'user';

    /** @var string Bot page */
    public const string BOT = 'bot';

    /** @var string Moderator page */
    public const string MODERATOR = 'moderator';

    /** @var string Admin page */
    public const string ADMIN = 'admin';

    /** @var string Admin users page */
    public const string ADMIN_USERS = 'admin_users';

    /** @var string Admin moderator page */
    public const string ADMIN_MODERATOR = 'admin_moderator';

    /** @var string Admin bots page */
    public const string ADMIN_BOTS = 'admin_bots';
}
