<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;

/**
 * The tasks demo's notifications library - a name and nothing else (HIL-771).
 *
 * Unlike the sessions and users libraries, this one asks a project for no seam at all:
 * notifications have no project half. A notification row names a recipient by id and carries
 * text already rendered, so nothing in the set needs a demo's own tables to be written.
 *
 * The class exists because every Hilos agent is mounted through a concrete class in the
 * project's registry, and an empty subclass is what that costs when the framework owns the
 * whole behavior ({@see AbstractNotificationsLibraryAgent}).
 *
 * Registered under {@see HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY} by this demo's own
 * topology, which {@see HilosFeature::NOTIFICATIONS} requires of every project declaring it.
 */
final class NotificationsLibraryAgent extends AbstractNotificationsLibraryAgent
{
}
