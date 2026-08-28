<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the emit seam after the move to the notifications library (HIL-771).
 *
 * What these pin is the seam itself rather than the write behind it: the facade no longer
 * writes anything, it names one frame and hands the draft over whole. Three things can break
 * that silently and none of them needs a database - the frame's name and shape, the draft
 * surviving the trip field for field, and the library still declaring itself the destination
 * routing resolves that name to.
 *
 * The write is exercised where it now lives, against real tables, by the chat demo's
 * notification-center integration case.
 */
final class NotificationEmitSeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The declaration is what makes the frame arrive anywhere: routing takes an agent signal's
     * destination from whoever names it, so an emit with no declared owner is queued and
     * dropped rather than refused.
     */
    public function testTheLibraryDeclaresItselfTheDestinationOfTheEmitFrame(): void
    {
        self::assertSame(
            NotificationEmitSignalData::class,
            AbstractNotificationsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_NOTIFICATION_EMIT] ?? null,
        );
        self::assertSame(
            HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            AbstractNotificationsLibraryAgent::AGENT_TYPE,
        );
    }

    public function testEmitQueuesTheDraftAsAnAgentFrameAndWritesNothing(): void
    {
        new HilosNotifier()->emit(new NotificationDraft(
            userId: 42,
            type: 'demo.test',
            title: 'A title',
            severity: NotificationSeverity::SUCCESS,
            body: 'A body',
            data: ['eventId' => 7],
            channels: ['email'],
        ));

        $signal = Hilos::$sr->getNextQueuedSignal();

        self::assertNotNull($signal, 'The emit seam queues exactly one frame');
        self::assertSame(HilosSignalConstants::HILOS_NOTIFICATION_EMIT, $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        self::assertInstanceOf(NotificationEmitSignalData::class, $signal->data->data);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The seam queues nothing else');
    }

    /**
     * The draft crosses a process boundary now, so every field has to survive the array it is
     * carried in - a field lost here is a notification that arrives with a piece missing and
     * nothing to say so.
     */
    public function testTheDraftSurvivesTheTripFieldForField(): void
    {
        $draft = new NotificationDraft(
            userId: 42,
            type: 'demo.test',
            title: 'A title',
            severity: NotificationSeverity::WARNING,
            body: 'A body',
            data: ['eventId' => 7],
            channels: ['email', 'telegram'],
        );

        $carried = NotificationEmitSignalData::fromArray(
            NotificationEmitSignalData::fromDraft($draft)->toArray(),
        )->toDraft();

        self::assertSame($draft->userId, $carried->userId);
        self::assertSame($draft->type, $carried->type);
        self::assertSame($draft->title, $carried->title);
        self::assertSame($draft->severity, $carried->severity);
        self::assertSame($draft->body, $carried->body);
        self::assertSame($draft->data, $carried->data);
        self::assertSame($draft->channels, $carried->channels);
    }

    /**
     * A draft that narrowed nothing must not arrive narrowed to nothing: the dispatcher reads
     * null as "every enabled channel" and a list as "only these", so an empty list surviving
     * as one would deliver the notification nowhere at all.
     */
    public function testAnUnnarrowedDraftArrivesUnnarrowed(): void
    {
        $carried = NotificationEmitSignalData::fromArray(
            NotificationEmitSignalData::fromDraft(new NotificationDraft(
                userId: 42,
                type: 'demo.test',
                title: 'A title',
            ))->toArray(),
        )->toDraft();

        self::assertNull($carried->channels);
        self::assertNull($carried->body);
        self::assertNull($carried->data);
        self::assertSame(NotificationSeverity::INFO, $carried->severity);
    }
}
