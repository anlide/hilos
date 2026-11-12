<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two steps of the admin delivery retry (HIL-771, HIL-1910).
 *
 * The retry is the one submit of HIL-771 that did NOT move to its owner, and the shape it got
 * instead is made of declarations that only hold together: the action stays on the page,
 * because the ADMIN level closing it exists on a page and nowhere else; the request frame is
 * declared by the library, which only names the row's owner and creates rows but never changes
 * them; the write frame is declared by the channel that owns the row, under the shard key it
 * routes by; the answer frame is declared back on the page, so the admin is answered by the
 * surface they submitted to; and the facade keeps no writer of its own.
 *
 * Each one alone has a defect with no symptom in the others' tests. The action declared on the
 * library instead would open the retry to anybody signed in. An Update left with the library
 * would be a second writer of a row its channel owns. The answer frame declared nowhere would
 * leave every tracked retry hanging, because the page deferred its own ack when it forwarded
 * the work.
 */
final class DeliveryRetryTwoStepTest extends TestCase
{
    public function testTheRetryActionStaysOnTheAdminPage(): void
    {
        self::assertSame(
            [HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY],
            array_keys(AbstractHilosCommunicationsDeliveriesPage::ACTIONS),
        );

        self::assertArrayNotHasKey(
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            AbstractNotificationsLibraryAgent::AGENT_ACTIONS,
        );
    }

    public function testTheLibraryIsAddressedByTheRequestAndOnlyCreatesDeliveries(): void
    {
        self::assertSame(
            DeliveryRetrySignalData::class,
            AbstractNotificationsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_DELIVERY_RETRY] ?? null,
        );
        self::assertSame(
            [TruthSourceOperation::Add],
            AbstractNotificationsLibraryAgent::OWNS_DB[HilosDbContext::notificationDeliveries],
        );
    }

    /**
     * Each channel that writes delivery rows is addressed by its own retry frame, by the shard
     * that holds the row, and its kind carries the Update the reset needs.
     */
    public function testTheRetryIsRoutedToTheChannelThatWritesTheRow(): void
    {
        $channels = [
            MailDeliveryChannelAgent::class => new MailDeliveryChannel(),
            SmsDeliveryChannelAgent::class => new SmsDeliveryChannel(),
            PushDeliveryChannelAgent::class => new PushDeliveryChannel(),
        ];
        foreach ($channels as $agent => $channel) {
            self::assertInstanceOf(AbstractDeliveryChannel::class, $channel);
            self::assertTrue(is_subclass_of($agent, AbstractDeliveryChannelAgent::class));
            self::assertSame([
                AgentSignalConfigKey::INDEX_FIELD => NotificationDeliveryRetrySignalData::shardKey,
                AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class,
            ], $agent::AGENT_SIGNALS[$channel->retrySignalName()] ?? null);
            self::assertArrayHasKey(HilosDbContext::notificationDeliveries, $agent::OWNS_DB);
            self::assertTrue($agent::defaultTruthSourceOperations()->allows(TruthSourceOperation::Update));
        }
    }

    public function testThePageIsAddressedByTheAnswerFrame(): void
    {
        self::assertSame(
            HandoverAnswerSignalData::class,
            AbstractHilosCommunicationsDeliveriesPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE] ?? null,
        );
    }

    /**
     * Pinned as the whole public surface rather than as the absence of one name: the facade is a
     * door to the library now, and a second writer added back to it would be a write from
     * whichever worker called it - the very defect this leaf closes. Erasure deletes its
     * notification rows within the account transaction (HIL-1202).
     */
    public function testTheFacadeOffersNothingButTheEmitDoor(): void
    {
        self::assertSame(['emit'], get_class_methods(HilosNotifier::class));
    }
}
