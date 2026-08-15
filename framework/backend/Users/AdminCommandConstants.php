<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\CLI\Commands\AbstractSetAdminCommand;

/**
 * AdminCommandConstants - the wire vocabulary of the admin grant command channel.
 *
 * The CLI side ({@see AbstractSetAdminCommand}) builds the request payload and the agent
 * side ({@see AbstractHilosIndexAgent}) reads it, so both name the keys from here and can
 * never drift apart. Used by {@see CliCommands::ADMIN_GRANT} and
 * {@see CliCommands::ADMIN_REVOKE}, which share one payload shape and differ only in the
 * flag they carry.
 *
 * The reply repeats both fields rather than answering bare ok, so a caller reading only the
 * reply can name the user and the flag that was set.
 */
final class AdminCommandConstants
{
    /** @var string Request and reply key: target user id */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Request and reply key: admin flag the command sets */
    public const string FIELD_ADMIN = 'admin';
}
