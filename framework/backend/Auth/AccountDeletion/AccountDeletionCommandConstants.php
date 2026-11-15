<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\AccountTestForcePurgeCommand;
use Hilos\Users\AccountErasureProgress;

/**
 * AccountDeletionCommandConstants - the wire vocabulary of
 * {@see CliCommands::ACCOUNT_TEST_FORCE_PURGE}.
 *
 * The CLI side ({@see AccountTestForcePurgeCommand}) sends the person whose standing
 * deletion request must be carried out, and the session holder answers with the project's
 * own tally under the names that project chose. The reply names its outcome
 * ({@see AccountDeletionCommandOutcome}); an erasure still under way travels as the block the
 * people card reads ({@see AccountErasureProgress}).
 */
final class AccountDeletionCommandConstants
{
    /** @var string Request and reply key: user whose account is erased */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Reply key: the project's rows erased, counted per family it names */
    public const string FIELD_ROWS_ERASED = 'rowsErased';

    /** @var string Reply key: the stage the erasure reached when the holder answered */
    public const string FIELD_OUTCOME = 'outcome';

    /** @var string Reply key: the progress of an erasure still under way */
    public const string FIELD_ERASURE = 'erasure';
}
