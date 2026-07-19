<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatCliCommands - chat-specific CLI command names.
 *
 * The `test:` commands are test-only helpers demonstrating the TestOnlyCommand
 * mechanism (orphan-settings state, the command-channel echo probe). The
 * `admin:` commands are real operator commands that flip a user's admin flag
 * over the daemon command channel; the `impersonate:` commands are real operator
 * commands that make an admin session act as another user and revert it.
 */
final class ChatCliCommands
{
    /** @var string Test-only: create the example orphan settings row */
    public const string CREATE_ORPHAN_SETTING = 'test:orphan-setting:create';

    /** @var string Test-only: delete the example orphan settings row */
    public const string DELETE_ORPHAN_SETTING = 'test:orphan-setting:delete';

    /** @var string Test-only: create a true orphan settings row for a non-catalog key */
    public const string CREATE_ORPHAN = 'test:orphan:create';

    /** @var string Test-only: delete a true orphan settings row for a non-catalog key */
    public const string DELETE_ORPHAN = 'test:orphan:delete';

    /** @var string Test-only: echo a message through the command channel via an agent */
    public const string COMMAND_ECHO = 'test:command:echo';

    /** @var string Grant a user admin rights over the command channel */
    public const string ADMIN_GRANT = 'admin:grant';

    /** @var string Revoke a user's admin rights over the command channel */
    public const string ADMIN_REVOKE = 'admin:revoke';

    /** @var string Start impersonating a user on a session over the command channel */
    public const string IMPERSONATE_START = 'impersonate:start';

    /** @var string Stop impersonating on a session over the command channel */
    public const string IMPERSONATE_STOP = 'impersonate:stop';

    /** @var string Create one backup archive of all database connections */
    public const string BACKUP_RUN = 'backup:run';
}
