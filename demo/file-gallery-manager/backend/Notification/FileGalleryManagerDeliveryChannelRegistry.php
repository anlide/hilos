<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;

/**
 * FileGalleryManagerDeliveryChannelRegistry - this demo's delivery-channel registry.
 *
 * Registers the email channel ({@see MailDeliveryChannel}) alone, so the notification dispatcher
 * fans to it and the admin communications surface shows it.
 */
final class FileGalleryManagerDeliveryChannelRegistry extends DeliveryChannelRegistry
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
