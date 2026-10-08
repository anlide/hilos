<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\SecondFactorUnlockCommand;

/**
 * SecondFactorUnlockCommandConstants - the wire vocabulary of {@see CliCommands::SECOND_FACTOR_UNLOCK} (HIL-1285).
 *
 * The CLI side ({@see SecondFactorUnlockCommand}) builds the request and prints the reply, the
 * users library ({@see AbstractUsersLibraryAgent}) reads the request and answers, so both name
 * the keys from here. The request carries the person; the reply repeats them and says whether
 * a lock was in force and until when, so the operator learns what the command lifted.
 */
final class SecondFactorUnlockCommandConstants
{
    /** @var string Request and reply key: person whose app-code lock is lifted */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Reply key: whether a lock was in force when it was lifted */
    public const string FIELD_WAS_LOCKED = 'wasLocked';

    /** @var string Reply key: end of the lifted lock (SQL datetime), null when none was in force */
    public const string FIELD_LOCKED_UNTIL = 'lockedUntil';
}
