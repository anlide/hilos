<?php

declare(strict_types=1);

namespace Demo\Chat\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Sms\Delivery\SmsDeliveryChannel;

/**
 * ChatDeliveryChannelRegistry - the chat demo's delivery-channel registry (HIL-200).
 *
 * Registers the email channel ({@see MailDeliveryChannel}) and the SMS channel
 * ({@see SmsDeliveryChannel}) so the notification dispatcher fans to them and the admin
 * communications surface lists them. Further channels (push 199, telegram 198) merge in
 * here as they land.
 */
final class ChatDeliveryChannelRegistry extends DeliveryChannelRegistry
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
