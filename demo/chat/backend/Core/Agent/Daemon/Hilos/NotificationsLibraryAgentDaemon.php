<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon\Hilos;

use Hilos\Notification\Library\AbstractNotificationsLibraryAgentDaemon;

/**
 * NotificationsLibraryAgentDaemon - daemon proxy for the chat notifications library (HIL-771).
 *
 * Inherits the framework placement whole: a monopolistic singleton, because the notification
 * tables have one writer or they have none. Where that process runs is left to the placement
 * policy in this demo's registry entry - an emit arrives from every worker, so there is no
 * reason to put it on the leader.
 */
final class NotificationsLibraryAgentDaemon extends AbstractNotificationsLibraryAgentDaemon
{
}
