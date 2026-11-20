<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Agents\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;

/**
 * The site's notifications library - a name and nothing else.
 *
 * The site sends no notifications; it declares {@see HilosFeature::NOTIFICATIONS} only because the
 * mail agent its codes leave by is a delivery channel agent, which needs
 * {@see HilosFeature::NOTIFICATION_DELIVERY}, and that feature stands on this one. The codes are a
 * raw send and write no row of the delivery journal, so the journal stays empty, and the feature
 * asks for its one writer all the same. Notifications have no project half, so the class asks
 * the site for no seam: the framework owns the whole behavior
 * ({@see AbstractNotificationsLibraryAgent}).
 *
 * Registered under {@see HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY} by the site's own topology.
 */
final class NotificationsLibraryAgent extends AbstractNotificationsLibraryAgent
{
}
