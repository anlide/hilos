<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\LegalTestHoldCommand;

/**
 * LegalHoldCommandConstants - the wire vocabulary of
 * {@see CliCommands::LEGAL_TEST_HOLD}.
 *
 * The CLI side ({@see LegalTestHoldCommand}) sends the person, document and revision to hold,
 * and the users library ({@see AbstractUsersLibraryAgent}) answers with the resulting document
 * standing and account freeze status.
 */
final class LegalHoldCommandConstants
{
    /** @var string Request and reply key: user whose stored acceptances are modified */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Request and reply key: legal document identifier */
    public const string FIELD_DOCUMENT = 'document';

    /** @var string Request and reply key: revision held */
    public const string FIELD_REVISION_ID = 'revisionId';

    /** @var string Reply key: document standing ('none'|'covered'|'window'|'lapsed') */
    public const string FIELD_STANDING = 'standing';

    /** @var string Reply key: deadline date (YYYY-MM-DD) or null */
    public const string FIELD_DEADLINE = 'deadline';

    /** @var string Reply key: whether the account is frozen */
    public const string FIELD_FROZEN = 'frozen';
}
