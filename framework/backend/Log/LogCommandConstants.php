<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;

/**
 * LogCommandConstants - the wire vocabulary of the log command channel (HIL-395).
 *
 * The CLI side reads the keys the agent side writes, so both name them from here and can
 * never drift apart. Only the test-only append uses it today
 * ({@see CliCommands::LOG_TEST_APPEND}, handled by {@see LogStoreAgent}), and it needs one
 * key of its own: the message it appends is the channel's common one
 * ({@see CommandConstants::FIELD_MESSAGE}), and only the line count is particular to logs.
 *
 * The vocabulary lives beside the feature rather than in the shared command constants for
 * the reason the throttle's does: a project that declares no log agent has no use for the
 * word, and a shared file would hand it the word anyway.
 */
final class LogCommandConstants
{
    /** @var string Request key: how many lines to append; reply key: how many were written */
    public const string FIELD_COUNT = 'count';
}
