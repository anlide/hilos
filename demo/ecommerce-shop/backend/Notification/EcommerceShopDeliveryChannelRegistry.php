<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;

/**
 * EcommerceShopDeliveryChannelRegistry - delivery-channel registry for the ecommerce-shop demo (HIL-1687).
 *
 * Registers the email channel ({@see MailDeliveryChannel}).
 */
final class EcommerceShopDeliveryChannelRegistry extends DeliveryChannelRegistry
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
