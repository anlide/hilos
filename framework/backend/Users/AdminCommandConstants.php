<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\SessionRebindConstants;
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
 * adds {@see self::FIELD_CREATED} and {@see self::FIELD_EXPIRED} to its reply, but the user
 * id and the flag it answers with mean exactly what the grant's do. Its halves are
 * {@see AdminCreateCommand} and {@see AbstractSessionsLibraryAgent}.
 *
 * The impersonation pair ({@see CliCommands::IMPERSONATE_START}, {@see CliCommands::IMPERSONATE_STOP})
 * addresses a session by the same key and adds {@see self::FIELD_TARGET_USER_ID} for the
 * person it is asked to act as. It does not answer from here: what a rebound session became
 * is reported in the frame's own vocabulary ({@see SessionRebindConstants}), read back off
 * the row rather than repeated from the request.
 */
final class AdminCommandConstants
{
    /** @var string Request and reply key: target user id */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Request and reply key: admin flag the command sets */
    public const string FIELD_ADMIN = 'admin';

    /** @var string Request key: cookie token naming the browser session the command acts on */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /** @var string Request key: user an impersonation is asked to act as */
    public const string FIELD_TARGET_USER_ID = 'targetUserId';

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

    /**
     * Reply key telling that the named session had outlived its expiry: true when it was
     * authenticated, the expiry rule unbound the user it carried, and the administrator is
     * therefore somebody new.
     *
     * Present in every ok reply, exactly like {@see self::FIELD_ADMIN} and
     * {@see self::FIELD_CREATED}: a key that appeared only on the one branch it describes
     * would leave a reader unable to tell "not expired" from "answered by an older daemon".
     *
     * The operator cannot infer this from anything else he is handed - the reply names a
     * user id he has never seen, and without this key it reads as the command having picked
     * the wrong session.
     *
     * @var string Reply key: whether the named session had expired
     */
    public const string FIELD_EXPIRED = 'expired';
}
