<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Constants;

/**
 * PollSignalConstants - simple-poll signal name constants.
 *
 * Defines signal name constants used in the simple-poll demo.
 */
final class PollSignalConstants
{
    /** @var string Handshake response signal name */
    public const string HANDSHAKE_RESPONSE = 'handshake_response';

    /** @var string Guest identity signal name - the display name of a session with no account */
    public const string GUEST_IDENTITY = 'guest_identity';
}
