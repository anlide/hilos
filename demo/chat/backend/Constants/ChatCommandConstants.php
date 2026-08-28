<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Users\AccountMergeCommandConstants;

/**
 * ChatCommandConstants - what chat still names on the command channel.
 *
 * Since HIL-729 the command names and their payload keys are the framework's, so what is
 * left here is the one thing the framework cannot name for us: the family of rows this
 * project moves when two accounts are merged. It goes back as a key inside
 * {@see AccountMergeCommandConstants::FIELD_ROWS_MOVED}, where the framework counts its own
 * identities and the project counts whatever it keeps for a person.
 */
final class ChatCommandConstants
{
    /** @var string Row family chat reports in an account merge: the messages it re-pointed */
    public const string ROWS_MOVED_MESSAGES = 'messages';
}
