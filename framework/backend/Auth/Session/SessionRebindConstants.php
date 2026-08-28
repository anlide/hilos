<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;

/**
 * SessionRebindConstants - what the sessions library answers an operator's rebind with.
 *
 * The reply to a command that ended in {@see SessionRebindSignalData} is written by
 * {@see AbstractSessionsLibraryAgent}, which is the only process that can see the outcome,
 * and read by whatever CLI command asked for it. The keys are the frame's own field names
 * because the reply IS the state the frame asked for, read back after it landed.
 */
final class SessionRebindConstants
{
    /** @var string Reply key: cookie token of the session that was rebound */
    public const string FIELD_SESSION_TOKEN = 'sessionToken';

    /** @var string Reply key: user the session acts as now, or null when it is anonymous */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Reply key: administrator behind the takeover, or null when there is none */
    public const string FIELD_IMPERSONATOR_USER_ID = 'impersonatorUserId';
}
