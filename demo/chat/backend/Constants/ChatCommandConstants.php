<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatCommandConstants - chat command-channel command names.
 *
 * Names of commands the chat daemon accepts over the CLI command socket channel,
 * routed to an agent via Hilos::getCommandAgentRoutes().
 */
final class ChatCommandConstants
{
    /** @var string Echo command: routed to the chat agent, which echoes the request payload back */
    public const string ECHO = 'echo';

    /** @var string Set-admin command: routed to the chat agent, which flips a user's admin flag */
    public const string SET_ADMIN = 'setAdmin';

    /** @var string Impersonate-start command: an admin session assumes another user's identity */
    public const string IMPERSONATE_START = 'impersonateStart';

    /** @var string Impersonate-stop command: reverts an impersonating session to its admin */
    public const string IMPERSONATE_STOP = 'impersonateStop';

    /** @var string Account-merge command: routed to the chat agent, which folds a loser account into a survivor */
    public const string ACCOUNT_MERGE = 'accountMerge';

    /** @var string Payload key: target user id for the set-admin command */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Payload key: new admin flag for the set-admin command */
    public const string FIELD_ADMIN = 'admin';

    /** @var string Payload key: session cookie token the impersonation commands act on */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /** @var string Payload key: user id to impersonate for the impersonate-start command */
    public const string FIELD_TARGET_USER_ID = 'targetUserId';

    /** @var string Reply key: the user id the session now acts as after an impersonation command */
    public const string FIELD_EFFECTIVE_USER_ID = 'effectiveUserId';

    /** @var string Reply key: the admin behind an impersonation started by impersonate-start */
    public const string FIELD_IMPERSONATOR = 'impersonator';

    /** @var string Payload key: survivor user id for the account-merge command */
    public const string FIELD_SURVIVOR_USER_ID = 'survivorUserId';

    /** @var string Payload key: loser user id for the account-merge command */
    public const string FIELD_LOSER_USER_ID = 'loserUserId';

    /** @var string Reply key: identities re-pointed to the survivor by an account merge */
    public const string FIELD_IDENTITIES_MOVED = 'identitiesMoved';

    /** @var string Reply key: messages re-pointed to the survivor by an account merge */
    public const string FIELD_MESSAGES_MOVED = 'messagesMoved';
}
