<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\CLI\Commands\AbstractSetAdminCommand;
use Hilos\Core\CLI\Commands\AdminCreateCommand;

/**
 * AdminCommandConstants - the wire vocabulary of the admin command channel.
 *
 * The CLI side ({@see AbstractSetAdminCommand}) builds the request payload and the agent
 * side ({@see AbstractHilosIndexAgent}) reads it, so both name the keys from here and can
 * never drift apart. Used by {@see CliCommands::ADMIN_GRANT} and
 * {@see CliCommands::ADMIN_REVOKE}, which share one payload shape and differ only in the
 * flag they carry.
 *
 * The reply repeats both fields rather than answering bare ok, so a caller reading only the
 * reply can name the user and the flag that was set.
 *
 * {@see CliCommands::ADMIN_CREATE} joins the same vocabulary rather than opening a second
 * one: it addresses a session ({@see self::FIELD_SESSION_TOKEN}) instead of a user id and
 * adds {@see self::FIELD_CREATED} to its reply, but the user id and the flag it answers with
 * mean exactly what the grant's do. Its halves are {@see AdminCreateCommand} and
 * {@see AbstractSessionsLibraryAgent}.
 */
final class AdminCommandConstants
{
    /** @var string Request and reply key: target user id */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Request and reply key: admin flag the command sets */
    public const string FIELD_ADMIN = 'admin';

    /** @var string Request key: cookie token naming the browser session to make an administrator */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /**
     * Reply key telling a mint from a grant: true when the session had no user and one was
     * minted for it, false when the user it already carried was flagged.
     *
     * An operator has to be able to tell the two apart - it decides whether he now owns a
     * second account - and after the bind nothing in the session says which happened.
     *
     * @var string Reply key: whether a user row was minted
     */
    public const string FIELD_CREATED = 'created';
}
