<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos;
use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;

/** Event moments retain signed milliseconds through the writer, object mapping and physical storage. */
final class EventTimestampMomentTest extends IntegrationTestCase
{
    /** The normal writer supplies its own current millisecond moment without a database default. */
    public function testChatStartedWritesCurrentMilliseconds(): void
    {
        $before = TimeHelper::nowMs();
        $event = Hilos::$db->events->actions->addChatStarted();
        $after = TimeHelper::nowMs();

        try {
            self::assertIsInt($event->timestamp);
            self::assertGreaterThanOrEqual($before, $event->timestamp);
            self::assertLessThanOrEqual($after, $event->timestamp);
            self::assertSame($event->timestamp, (int)Database::sql(
                'SELECT `timestamp` FROM `event` WHERE `id` = ?', [$event->id],
            )->field('timestamp'));
        } finally {
            Hilos::$db->events->actions->deleteByIds([(int)$event->id]);
        }
    }

    /** Zero, sub-second negative moments and a moment beyond 2038 survive without rounding or reinterpretation. */
    public function testExactSignedMillisecondRoundTrip(): void
    {
        foreach ([0, -1, -86399999, 2208988800456] as $timestamp) {
            $event = ObjectEvent::create();
            $event->type = ChatEventType::CHAT_STARTED->value;
            $event->timestamp = $timestamp;
            $event->sync();

            try {
                self::assertSame($timestamp, $event->timestamp);
                self::assertSame($timestamp, (int)Database::sql(
                    'SELECT `timestamp` FROM `event` WHERE `id` = ?', [$event->id],
                )->field('timestamp'));
                self::assertSame($timestamp, EntityEvent::get([EntityEvent::id => $event->id])->first()?->timestamp);
            } finally {
                $event->delete();
            }
        }
    }
}
