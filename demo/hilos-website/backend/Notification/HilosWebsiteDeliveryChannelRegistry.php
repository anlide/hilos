<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;

/**
 * HilosWebsiteDeliveryChannelRegistry - delivery-channel registry for the official site.
 *
 * Registers the email channel ({@see MailDeliveryChannel}) and nothing else. The site sends no
 * notifications of its own; the channel is here because {@see MailDeliveryChannelAgent} - the agent
 * the codes of a password reset and of an added password leave by - proves the rows it writes by
 * the one descriptor routed to it, and the topology check refuses the agent without one.
 */
final class HilosWebsiteDeliveryChannelRegistry extends DeliveryChannelRegistry
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
