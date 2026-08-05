<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Hilos;

/**
 * The framework-owned features a project can switch on.
 *
 * One case per unit of activation: something a project can take or leave on its own,
 * independently of the others. The project declares the ones it carries in
 * {@see Hilos::FEATURES}, and that declaration is the only place activation is stated -
 * before it existed, a feature was "on" because someone had mounted its runtime row or
 * registered its page, so the switch was spread across registries and could be half-flipped
 * without anyone noticing.
 *
 * The line between one case and two is drawn where the real projects diverge: the chat demo
 * delivers notifications over channels while the poll and todo demos only store them, so
 * delivery is its own case. Everything else is taken whole or not at all.
 *
 * Node freeze is deliberately absent and must not be added here: it is not optional surface but
 * the safeguard that has to exist wherever a destructive operation can be started, which is why
 * its runtime row is mounted for every project unconditionally and the freeze itself is switched
 * on by a CLI command rather than by a declaration.
 */
enum HilosFeature: string
{
    /** Admin settings page backed by the framework settings table and the project settings catalog. */
    case SETTINGS = 'settings';

    /** Framework user list and user detail admin pages over the project's own user table. */
    case HILOS_USERS = 'hilos_users';

    /** Backup subsystem: catalog, agent pair, history index and admin page. */
    case BACKUP = 'backup';

    /** Log archive admin pages plus the log overview and rotation agents. */
    case LOGS = 'logs';

    /** Durable notifications: storage, per-user preferences and the notifications page. */
    case NOTIFICATIONS = 'notifications';

    /** Delivery of notifications over channels (mail, SMS, push) on top of NOTIFICATIONS. */
    case NOTIFICATION_DELIVERY = 'notification_delivery';
}
