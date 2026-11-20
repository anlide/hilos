<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Core\Agent\Daemon\Hilos;

use Hilos\Notification\Library\AbstractNotificationsLibraryAgentDaemon;

/**
 * NotificationsLibraryAgentDaemon - daemon proxy for the site's notifications library.
 *
 * Inherits the framework placement whole: a monopolistic singleton, because the notification
 * tables have one writer or they have none. Where that process runs is left to the placement
 * policy in the site's registry entry.
 */
final class NotificationsLibraryAgentDaemon extends AbstractNotificationsLibraryAgentDaemon
{
}
