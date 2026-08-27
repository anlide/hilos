<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

/**
 * CommandChannelFailure - why a command-channel round-trip came back without a reply.
 *
 * Until HIL-728 there was one sentence for both cases, printed from twenty-five files:
 * "No reply from daemon (is it running?)". It answered its own question with a guess, and the
 * guess was wrong exactly half the time - a daemon that IS running but whose owning agent never
 * answered reads identically to one that was never started, and the two are fixed differently.
 */
enum CommandChannelFailure: string
{
    /** The command channel could not be reached at all: nothing is listening, or the socket broke. */
    case UNREACHABLE = 'unreachable';

    /** The channel was reached, but no reply arrived inside the wait budget. */
    case TIMEOUT = 'timeout';
}
