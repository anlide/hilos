<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;

/**
 * OnlineTestingDeliveryChannelRegistry - delivery-channel registry for the online-testing demo (HIL-1687).
 *
 * Registers the email channel ({@see MailDeliveryChannel}).
 */
final class OnlineTestingDeliveryChannelRegistry extends DeliveryChannelRegistry
{
    /**
     * @return array<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            MailDeliveryChannel::NAME => new MailDeliveryChannel(),
        ]);
    }
}
