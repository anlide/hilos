<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Notification\DTO\DeliveryRetryDoneSignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two halves of the admin delivery retry (HIL-771).
 *
 * The retry is the one submit of this leaf that did NOT move to its owner, and the shape it got
 * instead is made of four declarations that only hold together: the action stays on the page,
 * because the ADMIN level closing it exists on a page and nowhere else; the write frame is
 * declared by the library, which owns the journal; the answer frame is declared back on the
 * page, so the admin is answered by the surface they submitted to; and the facade keeps no
 * writer of its own.
 *
 * Each one alone has a defect with no symptom in the others' tests. The action declared on the
 * library instead would open the retry to anybody signed in. The answer frame declared nowhere
 * would leave every tracked retry hanging, because the page deferred its own ack when it
 * forwarded the work.
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

    public function testTheLibraryIsAddressedByTheWriteFrame(): void
    {
        self::assertSame(
            DeliveryRetrySignalData::class,
            AbstractNotificationsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_DELIVERY_RETRY] ?? null,
        );
    }

    public function testThePageIsAddressedByTheAnswerFrame(): void
    {
        self::assertSame(
            DeliveryRetryDoneSignalData::class,
            AbstractHilosCommunicationsDeliveriesPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE] ?? null,
        );
    }

    /**
     * Pinned as the whole public surface rather than as the absence of one name: the facade is a
     * door to the library now, and a second writer added back to it would be a write from
     * whichever worker called it - the very defect this leaf closes.
     */
    public function testTheFacadeOffersNothingButTheEmitDoor(): void
    {
        self::assertSame(['emit'], get_class_methods(HilosNotifier::class));
    }
}
