<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\ExitCode;

/**
 * DaemonDeparture - why the node left, as {@see DaemonManager::run()} reports it.
 *
 * The manager knows why it left; what number that is for the operating system is the
 * entrypoint's business, so the reason carries the mapping and {@see DaemonApplication}
 * only executes it.
 *
 * {@see self::Failed} means there was a failure on the node's way out, and NOT that the
 * node left unasked. An exit code answers "did the node leave healthy", and a node whose
 * loop iteration fell is unhealthy whenever the request to stop arrived - which is why
 * the order of the two does not come into it. How that plays out in the assignment is
 * written where the reason is kept ({@see DaemonManager::$departure}).
 *
 * Backed on purpose, unlike {@see AgentDeliveryOutcome}, whose value never leaves the
 * process: the value here is the words of the journal line the loop ends with, so the
 * ordinary departure keeps saying "Daemon stopped" and the forced one widens it.
 *
 * Two cases and no more: a separate code per reason - one for the entropy stop, one for a
 * failed iteration - was set aside by the owner on 2026-08-23 (review of proposal P-077)
 * until a supervisor exists that reads the difference.
 */
enum DaemonDeparture: string
{
    /** The node was asked to leave, or left on its own terms, with nothing having failed on the way */
    case Stopped = 'stopped';

    /** A failure happened on the node's way out, whether it started the departure or arrived during it */
    case Failed = 'stopped after a failure';

    /**
     * @return int Process exit code this departure is reported to a supervisor with
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Stopped => ExitCode::SUCCESS,
            self::Failed => ExitCode::ERROR,
        };
    }
}
