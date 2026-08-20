<?php

declare(strict_types=1);

namespace Demo\Tasks\Constants;

/**
 * TasksSignalConstants - tasks signal name constants.
 *
 * Defines signal name constants used in the tasks demo.
 */
final class TasksSignalConstants
{
    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string Guest identity signal name - the display name of a session with no account */
    public const string GUEST_IDENTITY = 'guest_identity';
}
