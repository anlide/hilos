<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Groups\AbstractHilosNotificationsGroup;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationGroup;
use Hilos\Notification\NotificationSignalName;

/**
 * Base class for the framework notification-center page (HIL-195).
 *
 * The recipient-scoped consumer of the durable notification model (HIL-102). It declares no
 * page subscription (no BROWSER) and, since HIL-771, no actions either: the
 * SubscriptionRegistry holds a single page per connection, so an always-on page
 * subscription would clobber the route page on every navigation. The live channel
 * is instead the per-user WebSocket group {@see NotificationGroup}
 * (`hilos_notifications:<userId>`), which the client joins with a `group_subscribe`
 * at connect and keeps for the connection's whole life; the recipient's other
 * devices stay in sync off the group signals
 * {@see NotificationSignalName::CREATED} / READ.
 *
 * The page hosts NO actions since HIL-771. It used to host the two write ones -
 * {@see NotificationAction::MARK_READ} and MARK_ALL_READ - and they moved to
 * {@see AbstractNotificationsLibraryAgent}, which owns the rows they write; the wire names
 * did not change, so the client still submits them exactly as before. What is left is a page
 * a project registers to activate the feature, with a `SUBSCRIPTION_AGENT_TYPE` and nothing
 * else. Whether a page with neither a subscription nor an action is still worth declaring is
 * a question for its own leaf, not for the move.
 *
 * The initial snapshot is NOT here: it is what the group answers a join with
 * ({@see AbstractHilosNotificationsGroup}), so the bell has it a frame earlier and stops
 * depending on the order two frames arrive in (HIL-721).
 *
 * The notification rows are still read process-wide rather than through
 * {@see AbstractPage::READS_DB} ({@see HilosDbContext::processWideReadCollections()}), and
 * after HIL-771 the reader is the GROUP rather than this page: a join is answered with the
 * snapshot in whatever worker serves that connection, and READS_DB is taken up when a page is
 * subscribed to, which this one never is. Whether that entry is still the right way to say so
 * belongs to the reader map (HIL-750).
 */
abstract class AbstractHilosNotificationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_NOTIFICATIONS;

    /**
     * Per-user surface, not an admin one: any signed-in user reads their own notifications.
     *
     * It closed the page's actions too, until they left for the library in HIL-771. That is
     * why the library declares all five of them in its own AUTH_ACTIONS: an agent action
     * carries no page level, and the guard has to travel with the action or it is lost.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}
