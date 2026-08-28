<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\AccountMergeCommand;
use Hilos\Database\Identity\PasswordFate;

/**
 * AccountMergeCommandConstants - the wire vocabulary of {@see CliCommands::ACCOUNT_MERGE}.
 *
 * The CLI side ({@see AccountMergeCommand}) builds the request payload and reads the reply,
 * the agent side answers it, so both name the keys from here and can never drift apart. It
 * is a vocabulary of its own rather than a corner of {@see AdminCommandConstants}, because a
 * merge addresses two users at once and neither of them is "the target": the admin pair
 * speaks of one user and one flag, and folding a survivor and a loser in beside them would
 * leave every reader asking which of the two {@see AdminCommandConstants::FIELD_USER_ID} is.
 *
 * The reply reports the OUTCOME and not the request. {@see self::FIELD_PASSWORD_KEPT} is read
 * back off the account after the merge, so naming an account that turned out to have no
 * password is answered with what the person actually holds ({@see PasswordFate::NONE}) rather
 * than with the word that was typed.
 *
 * {@see self::FIELD_ROWS_MOVED} is a map and not a count because the framework does not know
 * what a project keeps: it moves the sign-in identities itself and asks the project to move
 * its own rows, and what comes back is that project's own tally under its own names - in a
 * chat, `messages`. A single number would have had to mean something across projects, and
 * there is nothing it could mean.
 */
final class AccountMergeCommandConstants
{
    /** @var string Request key: survivor user id that absorbs the loser */
    public const string FIELD_SURVIVOR_USER_ID = 'survivorUserId';

    /** @var string Request key: loser user id folded into the survivor */
    public const string FIELD_LOSER_USER_ID = 'loserUserId';

    /** @var string Request key: whose password the operator asked the merge to keep */
    public const string FIELD_PASSWORD_FATE = 'passwordFate';

    /** @var string Reply key: whose password the merged account ended up with */
    public const string FIELD_PASSWORD_KEPT = 'passwordKept';

    /** @var string Reply key: sign-in identities re-pointed to the survivor */
    public const string FIELD_IDENTITIES_MOVED = 'identitiesMoved';

    /** @var string Reply key: the project's own rows re-pointed, counted per family it names */
    public const string FIELD_ROWS_MOVED = 'rowsMoved';

    /** @var string Option name: whose password to keep, on the merge command line */
    public const string OPTION_PASSWORD = 'password';
}
