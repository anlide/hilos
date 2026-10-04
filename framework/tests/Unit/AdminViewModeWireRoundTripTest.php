<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the hidden mark survives the way from a worker to the master (HIL-1250).
 *
 * A frame a worker builds for a connection is carried to the master, which holds the socket, as an
 * envelope of its data and its class, and rebuilt there by the class's own fromArray(). A typed reader
 * that took the mark for a malformed value - turned it into null, or refused the frame - would undo
 * the bridge after it was crossed. The frames of table rows and of the page answer carry rows and data
 * as arrays they do not look into, and this pins it; a frame of a page that keeps a subscriber set of
 * its own is sent to a viewer untyped, and that one is pinned too.
 */
final class AdminViewModeWireRoundTripTest extends TestCase
{
    /**
     * @return array<string, array{0: SignalDataInterface}> Every frame a hidden value rides to the master in
     */
    public static function framesCarryingTheMark(): array
    {
        $row = [
            PagePayload::rowKey => 'alpha',
            PagePayload::slots => [
                'person' => ['key' => 'alpha', 'name' => HiddenValue::mark(), 'seen' => '2026-09-01'],
                'note' => HiddenValue::mark(),
                'history' => [['name' => HiddenValue::mark()], HiddenValue::mark()],
            ],
        ];

        return [
            'page answer' => [new PageResponseSignalData('users', new PagePayload(
                data: ['pageLabel' => 'People', 'userName' => HiddenValue::mark()],
                windows: ['hilosUsers' => [TableWindowSignalData::rows => [$row]]],
            ))],
            'table window' => [new TableWindowSignalData(
                page: 'users',
                tableKey: 'hilosUsers',
                rows: [$row],
                totalCount: 1,
                totalExact: true,
                limit: 10,
                firstAnchor: TableAnchorDTO::fromRow(['seen' => '2026-09-01', 'key' => 'alpha'], ['seen', 'key']),
                lastAnchor: null,
                rowsBefore: 0,
            )],
            'row delta' => [TableViewportDeltaDTO::rowUpdated('users', 'hilosUsers', 'alpha', $row)],
            'row taken out with its body' => [TableViewportDeltaDTO::rowRemoved(
                'users',
                'hilosUsers',
                'alpha',
                TableViewportDeltaDTO::REASON_MOVED_OUT,
                false,
                $row,
            )],
            'append' => [new TableViewportAppendDTO('users', 'hilosUsers', $row, 2, true, 1, null, null)],
            'own create' => [new TableViewportOwnCreateDTO('users', 'hilosUsers', $row, 0, 2, true, 1, null, null, 'req-1')],
            'progress' => [TableProgressSignalData::fromProgress('backup', 'hilosBackupHistory', new TableProgressDTO(
                TableProgressScope::Table,
                'run',
                null,
                3,
                10,
                false,
                ['file' => HiddenValue::mark()],
            ))],
            'frame of a page with its own subscriber set' => [new SignalData([
                'keys' => [['key' => 'daemon', 'size' => HiddenValue::mark()]],
                'node' => HiddenValue::mark(),
            ])],
            'log follow with hidden text' => [new LogsLinesAppendedSignalData(
                'follow-1',
                [[
                    LogsReadLinesReplyDTO::time => '2026-09-01 01:02:03.004',
                    LogsReadLinesReplyDTO::text => HiddenValue::mark(),
                    LogsReadLinesReplyDTO::level => 'INFO',
                    LogsReadLinesReplyDTO::isContinuation => false,
                ]],
                false,
                null,
                false,
            )],
            'log follow with visible text' => [new LogsLinesAppendedSignalData(
                'follow-2',
                [[
                    LogsReadLinesReplyDTO::time => null,
                    LogsReadLinesReplyDTO::text => 'visible continuation',
                    LogsReadLinesReplyDTO::level => 'INFO',
                    LogsReadLinesReplyDTO::isContinuation => true,
                ]],
                false,
                null,
                false,
            )],
        ];
    }

    #[DataProvider('framesCarryingTheMark')]
    public function testTheMarkArrivesAtTheMasterAsItLeftTheWorker(SignalDataInterface $frame): void
    {
        $carried = json_decode(json_encode(SignalDataEnvelope::encode($frame), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($carried);

        $rebuilt = SignalDataEnvelope::decode(
            $carried[SignalPayloadConstants::FIELD_DATA],
            $carried[SignalPayloadConstants::FIELD_DATA_TYPE],
        );

        $this->assertInstanceOf($frame::class, $rebuilt);
        $this->assertSame($frame->toArray(), $rebuilt->toArray());
    }

    public function testTheLogReadReplyKeepsHiddenAndVisibleTextInTheirOwnShape(): void
    {
        foreach ([HiddenValue::mark(), 'visible'] as $text) {
            $reply = new LogsReadLinesReplyDTO(true, [[
                LogsReadLinesReplyDTO::time => '2026-09-01 01:02:03.004',
                LogsReadLinesReplyDTO::text => $text,
                LogsReadLinesReplyDTO::level => 'INFO',
                LogsReadLinesReplyDTO::isContinuation => false,
            ]], null, false);

            $this->assertSame($reply->toArray(), LogsReadLinesReplyDTO::fromArray($reply->toArray())->toArray());
        }
    }
}
