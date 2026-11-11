<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Notification;

use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannel;

/**
 * RobloxGameServerDeliveryChannelRegistry - this demo's delivery-channel registry.
 *
 * Registers the three channels the communications spec lists and edits: the email channel
 * ({@see MailDeliveryChannel}), the SMS channel ({@see SmsDeliveryChannel}) and the web-push
 * channel ({@see PushDeliveryChannel}), so the notification dispatcher fans to them and the admin
 * communications surface shows them. No VAPID keys are set here: the push channel is listed and
 * configured, and nothing of this demo sends through it yet.
 */
final class RobloxGameServerDeliveryChannelRegistry extends DeliveryChannelRegistry
{
    /**
     * @return array<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            MailDeliveryChannel::NAME => new MailDeliveryChannel(),
            SmsDeliveryChannel::NAME => new SmsDeliveryChannel(),
            PushDeliveryChannel::NAME => new PushDeliveryChannel(),
        ]);
    }
}
