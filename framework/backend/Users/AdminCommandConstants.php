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
 * Since HIL-849 the grant reply also says who was TOLD about the write, in
 * {@see self::FIELD_ANNOUNCED}, {@see self::FIELD_ANNOUNCED_SESSIONS} and
 * {@see self::FIELD_ANNOUNCE_ERROR}: the flag reaching the row and the flag reaching the
 * person's open tabs are two halves of the operation, and an announcement that failed leaves
 * the write standing - so the reply stays ok and names the half that did not happen.
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

    /**
     * Reply key telling whether the announcement pass ran to the end: true when every live
     * session of the person was told, false when it stopped on a failure.
     *
     * Present in every ok reply for the same reason {@see self::FIELD_EXPIRED} is - a key
     * that appeared only on the branch it describes would leave a reader unable to tell
     * "nothing failed" from "answered by an older daemon".
     *
     * It does not answer whether anybody was there to tell: a person with no tab open is
     * announced to nobody and the pass still ran to the end. That question belongs to
     * {@see self::FIELD_ANNOUNCED_SESSIONS}.
     *
     * @var string Reply key: whether the announcement pass ran to the end
     */
    public const string FIELD_ANNOUNCED = 'announced';

    /**
     * Reply key counting the live sessions that received the state frame.
     *
     * Present in every ok reply, and not derivable from {@see self::FIELD_ANNOUNCED}: zero
     * means an offline person on a successful pass and a failure on the very first session on
     * a failed one, and the operator reads the two differently.
     *
     * On a partial pass it is honest about what got through rather than rounded to nothing -
     * the sessions told before the failure keep the new rights, and only the rest wait for a
     * reconnect.
     *
     * @var string Reply key: live sessions that received the state frame
     */
    public const string FIELD_ANNOUNCED_SESSIONS = 'announcedSessions';

    /**
     * Reply key carrying why the announcement stopped, null when it ran to the end.
     *
     * Present in every ok reply, null included: an absent key on an older daemon would read
     * as a pass with nothing to report, which is precisely the reading this key exists to
     * deny.
     *
     * The daemon's own words travel to the operator rather than staying in the worker log,
     * because he diagnoses from the terminal he typed into and has no reason to hold a log
     * file open. The log is written as well, for whoever looks later.
     *
     * @var string Reply key: why the announcement stopped, null on success
     */
    public const string FIELD_ANNOUNCE_ERROR = 'announceError';
}
