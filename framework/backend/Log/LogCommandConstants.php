<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;

/**
 * LogCommandConstants - the wire vocabulary of the log command channel (HIL-395).
 *
 * The CLI side reads the keys the agent side writes, so both name them from here and can
 * never drift apart. The test-only commands {@see LogStoreAgent} answers are what use it:
 * the append ({@see CliCommands::LOG_TEST_APPEND}) needs one key of its own, since the
 * message it appends is the channel's common one ({@see CommandConstants::FIELD_MESSAGE})
 * and only the line count is particular to logs; the rotation
 * ({@see CliCommands::LOGS_TEST_ROTATE}) answers with how many files it moved; the walk
 * ({@see CliCommands::LOGS_TEST_WALK}) carries nothing either way.
 *
 * The vocabulary lives beside the feature rather than in the shared command constants for
 * the reason the throttle's does: a project that declares no log agent has no use for the
 * word, and a shared file would hand it the word anyway.
 */
final class LogCommandConstants
{
    /** @var string Request key: how many lines to append; reply key: how many were written */
    public const string FIELD_COUNT = 'count';

    /** @var string Reply key of the rotation: how many live log files it moved into the new batch */
    public const string FIELD_MOVED = 'moved';
}
