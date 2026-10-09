<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Sms\Delivery\SmsDeliveryChannel;

/**
 * BinanceBtcTrackerDeliveryChannelRegistry - this demo's delivery-channel registry (HIL-1224).
 *
 * Registers two channels, exactly the ones this demo's specs drive: the email channel
 * ({@see MailDeliveryChannel}), which the notification specs switch on, mute and read the
 * letter of, and the SMS channel ({@see SmsDeliveryChannel}), whose sender field the
 * communications spec edits. Web push is not switched on here: no spec of this demo drives it,
 * and it would bring VAPID keys and the devices page along. The hilos_push_subscription table is
 * already here, with notifications (HIL-1296).
 */
final class BinanceBtcTrackerDeliveryChannelRegistry extends DeliveryChannelRegistry
{
    /**
     * @return array<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            MailDeliveryChannel::NAME => new MailDeliveryChannel(),
            SmsDeliveryChannel::NAME => new SmsDeliveryChannel(),
        ]);
    }
}
