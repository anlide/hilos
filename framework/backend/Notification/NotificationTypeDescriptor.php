<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationTypeDescriptor - the code-side declaration of one notification type (HIL-485).
 *
 * A type is described where the code that emits it lives, not in the
 * hilos_notification row. Today it declares just one trait: {@see $mandatory}. A
 * mandatory notification (e.g. a security alert) ignores a recipient's per-user
 * channel preferences (HIL-485) and goes out on every globally enabled channel
 * where the recipient has an address; it does not override a global channel switch
 * (HIL-200) nor conjure an address. A non-mandatory type is subject to per-user
 * preferences once HIL-485 lands.
 */
final class NotificationTypeDescriptor
{
    /**
     * @param bool $mandatory Whether the type bypasses per-user channel preferences
     */
    public function __construct(
        public readonly bool $mandatory = false,
    ) {
    }
}
