<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Socket\WebSocket\Delivery\DeliveryCoalescer;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DeliveryCoalescerTest extends TestCase
{
    /** @return iterable<string, array{stdClass}> Shared cases for the subsequent real TS normalizer/controller parity */
    public static function fixtures(): iterable
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 4) . '/frontend/core/test/state/fixtures/delivery-coalescing.json'));
        foreach ($fixture->cases as $case) {
            yield $case->name => [$case];
        }
    }

    /** @param stdClass $case Original/coalesced/expected JSON boundary case */
    #[DataProvider('fixtures')]
    public function testSharedFixtureUsesTheRealMerger(stdClass $case): void
    {
        $coalescer = new DeliveryCoalescer();
        $originals = [];
        foreach ($case->original as $wire) {
            $originals[] = DeliveryFrame::fromEnvelope($wire);
            $coalescer->append($originals[array_key_last($originals)]);
        }
        self::assertTrue($coalescer->hasFrames());
        $actual = array_map(static fn (DeliveryFrame $frame): ?stdClass => $frame->getEnvelope(), $coalescer->drain());
        self::assertSame(self::canonicalJson($case->coalesced), self::canonicalJson($actual), $case->name);
        self::assertFalse($coalescer->hasFrames());
        self::assertSame([], $coalescer->drain());
        foreach ($originals as $index => $frame) {
            self::assertSame(self::canonicalJson($case->original[$index]), self::canonicalJson($frame->getEnvelope()));
        }
    }

    public function testWholeStatesCountsAndProgressKeepTheOrderOfTheirLastOccurrences(): void
    {
        $coalescer = new DeliveryCoalescer();
        $state1 = self::frame('subscription_page_demo', ['page' => 'p', 'value' => 1]);
        $state2 = self::frame('subscription_page_demo', ['page' => 'p', 'value' => 2]);
        $otherPage = self::frame('subscription_page_demo', ['page' => 'other', 'value' => 3]);
        $count1 = self::frame('table_viewport_count', ['page' => 'p', 'tableKey' => 't', 'total' => 1]);
        $count2 = self::frame('table_viewport_count', ['page' => 'p', 'tableKey' => 't', 'total' => 2]);
        foreach ([$state1, $count1, $otherPage, $state2, $count2] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$otherPage, $state2, $count2], $coalescer->drain());

        $runA = self::progress('a', 'r', 1);
        $runB = self::progress('b', 'r', 2);
        $runALast = self::progress('a', 'r', 9);
        $otherRow = self::progress('a', 's', 8);
        foreach ([$runA, $runB, $runALast, $otherRow] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$runB, $runALast, $otherRow], $coalescer->drain());
    }

    public function testWholeStateListsAndProgressScopesHaveIndependentAddresses(): void
    {
        $coalescer = new DeliveryCoalescer();
        $state1 = DeliveryFrame::fromJson('{"type":"subscription_page_demo","data":[1]}');
        $state2 = DeliveryFrame::fromJson('{"type":"subscription_page_demo","data":[]}');
        $coalescer->append($state1);
        $coalescer->append($state2);
        self::assertSame([$state2], $coalescer->drain());
        $table = self::frame('table_progress', ['page' => 'p', 'tableKey' => 't', 'scope' => 'table', 'progressKey' => 'a']);
        $other = self::frame('table_progress', ['page' => 'p', 'tableKey' => 'other', 'scope' => 'table', 'progressKey' => 'a']);
        $row = self::progress('a', 'r', 1);
        foreach ([$table, $row, $other, $table] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$row, $other, $table], $coalescer->drain());
    }

    public function testTypedProgressRowCollisionIsAnOrderedBoundary(): void
    {
        $coalescer = new DeliveryCoalescer();
        $numeric = self::frame('table_progress', [
            'page' => 'p', 'tableKey' => 't', 'scope' => 'row', 'progressKey' => 'a', 'rowKey' => 1, 'current' => 1,
        ]);
        $string = self::frame('table_progress', [
            'page' => 'p', 'tableKey' => 't', 'scope' => 'row', 'progressKey' => 'a', 'rowKey' => '1', 'current' => 2,
        ]);
        foreach ([$numeric, $string, $numeric] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$numeric, $string, $numeric], $coalescer->drain());
    }

    /** @return iterable<string, array{string, array<string, mixed>}> Ordered event/structural signal forms */
    public static function barriers(): iterable
    {
        foreach ([
            'subscription_page_error', 'project_signal', 'toast', 'chat_message', 'log_message', 'action_success',
            'action_error', 'table_bulk_report', 'table_window', 'table_window_refused', 'table_viewport_frozen',
            'table_viewport_append', 'table_viewport_announce', 'table_viewport_unannounce', 'table_viewport_own_create',
        ] as $type) {
            yield $type => [$type, ['page' => 'p', 'tableKey' => 't']];
        }
        foreach (['row_moved', 'row_removed', 'row_stale', 'future_kind'] as $kind) {
            yield $kind => ['table_viewport_delta', ['page' => 'p', 'tableKey' => 't', 'kind' => $kind, 'rowKey' => 'r']];
        }
    }

    /**
     * @param string $type Independently observable signal name
     * @param array<string, mixed> $body Boundary event body
     */
    #[DataProvider('barriers')]
    public function testEventsAndStructuralFramesAreKeptBetweenStateUpdates(string $type, array $body): void
    {
        $before = self::frame('subscription_page_demo', ['value' => 1]);
        $event = self::frame($type, $body);
        $after = self::frame('subscription_page_demo', ['value' => 2]);
        $coalescer = new DeliveryCoalescer();
        foreach ([$before, $event, $after] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$before, $event, $after], $coalescer->drain());
    }

    public function testMetadataSurvivesAndCorrelatedPatchesAreIndependent(): void
    {
        $before = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p","payload":{"data":{"x":1}}},"time":1}');
        $later = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p","payload":{"data":{"y":2}}},"time":9}');
        $ack = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p"},"requestId":"r","outcome":"success","time":10}');
        $coalescer = new DeliveryCoalescer();
        foreach ([$before, $later, $ack, $later] as $frame) {
            $coalescer->append($frame);
        }
        $survivors = $coalescer->drain();
        self::assertCount(3, $survivors);
        self::assertSame(9, $survivors[0]->getEnvelope()->time);
        self::assertEquals((object) ['x' => 1, 'y' => 2], $survivors[0]->getEnvelope()->data->payload->data);
        self::assertSame($ack, $survivors[1]);
        self::assertSame($later, $survivors[2]);
        self::assertSame('r', $survivors[1]->getEnvelope()->requestId);
        self::assertSame('success', $survivors[1]->getEnvelope()->outcome);
    }

    public function testFullAnswersOpaqueTextAndUnknownPatchShapesAreBoundaries(): void
    {
        $normal = self::frame('page_response', ['page' => 'p', 'payload' => ['data' => ['n' => 1]]]);
        $full = DeliveryFrame::fromJson($normal->json, subscriptionResponse: true);
        $boundaries = [
            $full,
            DeliveryFrame::fromJson('legacy text'),
            self::frame('page_response', ['page' => 'p', 'payload' => ['windows' => new stdClass()]]),
            self::frame('page_response', ['page' => 'p', 'payload' => ['refusedWindows' => new stdClass()]]),
            self::frame('page_response', ['page' => 'p', 'payload' => ['future' => 1]]),
            self::frame('page_response', ['page' => 'p', 'payload' => []]),
            self::frame('page_response', ['page' => 'p', 'payload' => ['tables' => ['t' => ['rows' => [], 'future' => 1]]]]),
        ];
        foreach ($boundaries as $boundary) {
            $coalescer = new DeliveryCoalescer();
            foreach ([$normal, $boundary, $normal] as $frame) {
                $coalescer->append($frame);
            }
            self::assertSame([$normal, $boundary, $normal], $coalescer->drain());
        }
    }

    public function testCrossCarrierWritesCannotMoveAnOldFieldPastANewerAlias(): void
    {
        $page = self::frame('page_response', ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'x' => 1]]]]);
        $viewport = DeliveryFrame::fromEnvelope(['type' => 'table_viewport_delta', 'data' => TableViewportDeltaDTO::rowUpdated(
            'p', 't', 'r', ['rowKey' => 'r', 'slots' => ['alias' => ['id' => 42, 'x' => 2]]],
        )->toArray()]);
        $last = self::frame('page_response', ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'y' => 3]]]]);
        $coalescer = new DeliveryCoalescer();
        foreach ([$page, $viewport, $last] as $frame) {
            $coalescer->append($frame);
        }
        self::assertSame([$page, $viewport, $last], $coalescer->drain());
    }

    public function testSharedFramesStayUnchangedAcrossIndependentCoalescers(): void
    {
        $shared = self::frame('page_response', ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'x' => 1]]]]);
        $a = new DeliveryCoalescer();
        $b = new DeliveryCoalescer();
        $a->append($shared);
        $b->append($shared);
        $a->append(self::frame('page_response', ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'y' => 2]]]]));
        self::assertSame($shared, $b->drain()[0]);
        self::assertFalse(property_exists($shared->getEnvelope()->data->payload->entities->thing, 'y'));
        self::assertSame(2, $a->drain()[0]->getEnvelope()->data->payload->entities->thing->y);
    }

    public function testClearDropsBothFramesAndIndices(): void
    {
        $coalescer = new DeliveryCoalescer();
        $coalescer->append(self::frame('subscription_page_demo', ['n' => 1]));
        $coalescer->clear();
        self::assertSame([], $coalescer->drain());
        $next = self::frame('subscription_page_demo', ['n' => 2]);
        $coalescer->append($next);
        self::assertSame([$next], $coalescer->drain());
    }

    public function testHeldBytesAreTheTextOfTheSurvivorsThroughReplaceMergeAndBoundaries(): void
    {
        $frames = [
            self::frame('table_viewport_count', ['page' => 'p', 'tableKey' => 't', 'total' => 100]),
            self::frame('page_response', ['page' => 'p', 'payload' => ['data' => ['x' => 1]]]),
            self::frame('table_viewport_count', ['page' => 'p', 'tableKey' => 't', 'total' => 2]),
            self::frame('page_response', ['page' => 'p', 'payload' => ['data' => ['y' => str_repeat('y', 40)]]]),
            self::frame('toast', ['text' => 'event']),
            DeliveryFrame::fromJson('opaque'),
            self::frame('page_response', ['page' => 'p', 'payload' => ['data' => ['x' => 3]]]),
        ];
        $replacedOrMerged = false;
        for ($length = 0; $length <= count($frames); $length++) {
            $coalescer = new DeliveryCoalescer();
            foreach (array_slice($frames, 0, $length) as $frame) {
                $coalescer->append($frame);
            }
            $held = [$coalescer->getFrameCount(), $coalescer->getHeldBytes()];
            $survivors = $coalescer->drain();
            $replacedOrMerged = $replacedOrMerged || count($survivors) < $length;
            self::assertSame(
                [count($survivors), array_sum(array_map(static fn (DeliveryFrame $frame): int => strlen($frame->json), $survivors))],
                $held,
                "after {$length} frames",
            );
            self::assertSame([0, 0], [$coalescer->getFrameCount(), $coalescer->getHeldBytes()]);
        }
        self::assertTrue($replacedOrMerged);

        $coalescer = new DeliveryCoalescer();
        foreach ($frames as $frame) {
            $coalescer->append($frame);
        }
        $coalescer->clear();
        self::assertSame([0, 0], [$coalescer->getFrameCount(), $coalescer->getHeldBytes()]);
    }

    /**
     * @param string $type Wire signal type
     * @param array<string, mixed> $body Wire body
     * @return DeliveryFrame Prepared fixture frame
     */
    private static function frame(string $type, array $body): DeliveryFrame
    {
        return DeliveryFrame::fromEnvelope(['type' => $type, 'data' => $body]);
    }

    /**
     * @param string $run Progress run key
     * @param string $row Row key
     * @param int $current Completed units
     * @return DeliveryFrame Prepared real progress DTO
     */
    private static function progress(string $run, string $row, int $current): DeliveryFrame
    {
        return DeliveryFrame::fromEnvelope(['type' => 'table_progress', 'data' => TableProgressSignalData::fromProgress(
            'p', 't', new TableProgressDTO(TableProgressScope::Row, $run, $row, $current, 10),
        )->toArray()]);
    }

    /**
     * Preserve JSON container and scalar types while ignoring object property order.
     *
     * @param mixed $value Fixture JSON boundary
     * @return string Canonical JSON for strict comparison
     */
    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortMaps($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param mixed $value Fixture JSON boundary
     * @return mixed Sorted detached maps, with lists untouched
     */
    private static function sortMaps(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sortMaps(...), $properties);
        }

        return is_array($value) ? array_map(self::sortMaps(...), $value) : $value;
    }
}
