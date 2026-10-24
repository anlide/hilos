<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmReplyDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the wire gate of the logs_read_lines action (HIL-757).
 *
 * This is the only place a read request is judged: the owner that carries it out lives on another
 * machine and answers on a socket it does not hold, so a request that names nothing usable has to
 * be refused HERE, correlated to the click, rather than travel and come back as an empty page.
 *
 * The file is named structurally - a source, a batch stamp and a stream - so what is checked is
 * that those three agree with each other, not that a path looks safe. The reader's traversal guard
 * remains the second line of defence, and the browser never gets to write a path at all.
 */
final class LogsReadLinesActionDtoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> Action and corresponding owner frame */
    public static function batchRequests(): iterable
    {
        yield 'read' => [LogsReadLinesActionDTO::class, LogsReadLinesSignalData::class];
        yield 'confirm' => [LogsTakeoutConfirmActionDTO::class, LogsTakeoutConfirmSignalData::class];
        yield 'undo' => [LogsTakeoutUndoActionDTO::class, LogsTakeoutUndoSignalData::class];
    }

    #[DataProvider('batchRequests')]
    public function testMillisecondsCrossBothTransportsExactlyOnce(string $actionClass, string $signalClass): void
    {
        $browser = [
            'nodeId' => 'node-2', 'batchTimestamp' => 1_800_000_000_000,
            'source' => 'batch', 'stream' => 'worker-0.log', 'level' => 'ERROR',
            'substring' => 'needle', 'anchorAtMs' => 1_800_000_000_123,
        ];
        $action = $actionClass::fromArray($browser);
        $this->assertSame(1_800_000_000, $action->batchTimestamp);
        $this->assertSame(1_800_000_000_000, $action->toArray()['batchTimestamp']);
        $signal = match ($signalClass) {
            LogsReadLinesSignalData::class => $signalClass::fromAction($action, 'accept', $action->getAction(), 'request', false),
            LogsTakeoutConfirmSignalData::class => $signalClass::fromAction($action, 'accept', $action->getAction(), 'request', 42),
            LogsTakeoutUndoSignalData::class => $signalClass::fromAction($action, 'accept', $action->getAction(), 'request'),
        };
        $this->assertSame(1_800_000_000, $signal->batchTimestamp);
        $wire = $signal->toArray();
        $this->assertSame(1_800_000_000_000, $wire['batchTimestamp']);
        $owner = $signalClass::fromArray($wire);
        $this->assertEquals($signal, $owner);
        $this->assertSame(1_800_000_000, $owner->batchTimestamp);
        $this->assertSame('node-2', $owner->nodeId);
        $this->assertSame('accept', $owner->acceptKey);
        $this->assertSame('request', $owner->requestId);
        if ($owner instanceof LogsReadLinesSignalData) {
            $this->assertSame(1_800_000_000_123, $owner->anchorAtMs);
            $this->assertSame('needle', $owner->substring);
            $this->assertNull($owner->cursor);
            $this->assertFalse($owner->hideText);
        }
        if ($owner instanceof LogsTakeoutConfirmSignalData) {
            $this->assertSame(42, $owner->userId);
        }
    }

    #[DataProvider('batchRequests')]
    public function testFractionalBatchSecondIsRefusedByTheAction(string $actionClass, string $signalClass): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('whole second in epoch milliseconds');
        $actionClass::fromArray([
            'nodeId' => 'node-2', 'source' => 'batch', 'stream' => 'worker-0.log', 'batchTimestamp' => 1_800_000_000_123,
        ]);
    }

    #[DataProvider('batchRequests')]
    public function testFractionalBatchSecondIsRefusedByTheOwnerFrame(string $actionClass, string $signalClass): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('whole second in epoch milliseconds');
        $signalClass::fromArray([
            'nodeId' => 'node-2', 'source' => 'batch', 'stream' => 'worker-0.log', 'batchTimestamp' => 1_800_000_000_123,
            'acceptKey' => 'accept', 'action' => 'action', 'requestId' => null, 'userId' => null, 'hideText' => false,
        ]);
    }

    public function testConfirmationAckConvertsItsStoredSecondsOnce(): void
    {
        $reply = new LogsTakeoutConfirmReplyDTO(1_800_000_123);
        $this->assertSame(['takenAt' => 1_800_000_123_000], $reply->toArray());
        $this->assertSame(1_800_000_123, LogsTakeoutConfirmReplyDTO::fromArray(['takenAt' => 1_800_000_123_000])->takenAt);
        $this->assertSame(1_800_000_123, $reply->takenAt);
    }

    public function testAWholeRequestIsReadIntoItsFields(): void
    {
        $dto = LogsReadLinesActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => [
            LogsReadLinesActionDTO::nodeId => 'node-B',
            LogsReadLinesActionDTO::source => LogsReadLinesActionDTO::SOURCE_BATCH,
            LogsReadLinesActionDTO::batchTimestamp => 1774000000000,
            LogsReadLinesActionDTO::stream => '  worker-0.log  ',
            LogsReadLinesActionDTO::level => Logger::LEVEL_ERROR,
            LogsReadLinesActionDTO::substring => 'timeout',
            LogsReadLinesActionDTO::cursor => 4096,
        ]]);

        $this->assertSame('node-B', $dto->nodeId);
        $this->assertSame(LogsReadLinesActionDTO::SOURCE_BATCH, $dto->source);
        $this->assertSame(1774000000, $dto->batchTimestamp);
        $this->assertSame('worker-0.log', $dto->stream);
        $this->assertSame(Logger::LEVEL_ERROR, $dto->level);
        $this->assertSame('timeout', $dto->substring);
        $this->assertSame(4096, $dto->cursor);
    }

    public function testAnEmptyNodeIdIsAcceptedAsThisNode(): void
    {
        $dto = LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::nodeId => '']));

        $this->assertSame('', $dto->nodeId, 'A standalone install publishes itself under an empty id');
    }

    /**
     * A stamp beside a live read would be a second opinion about which file this is, so it is
     * dropped rather than carried and ignored.
     */
    public function testALiveReadDropsABatchStampSentBesideIt(): void
    {
        $dto = LogsReadLinesActionDTO::fromArray($this->live([
            LogsReadLinesActionDTO::batchTimestamp => 1774000000000,
        ]));

        $this->assertNull($dto->batchTimestamp);
    }

    public function testAFilterlessReadIsAccepted(): void
    {
        $dto = LogsReadLinesActionDTO::fromArray($this->live());

        $this->assertNull($dto->level);
        $this->assertNull($dto->substring);
        $this->assertNull($dto->cursor, 'No cursor is the first page, read from the end of the file');
    }

    public function testASourceThatNamesNeitherHalfOfTheStoreIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Unknown log source: everything');

        LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::source => 'everything']));
    }

    public function testABatchReadThatNamesNoBatchIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([
            LogsReadLinesActionDTO::source => LogsReadLinesActionDTO::SOURCE_BATCH,
        ]));
    }

    public function testABatchStampBeforeTheEpochIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([
            LogsReadLinesActionDTO::source => LogsReadLinesActionDTO::SOURCE_BATCH,
            LogsReadLinesActionDTO::batchTimestamp => -1,
        ]));
    }

    public function testAReadNamingNoStreamIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::stream => '   ']));
    }

    /**
     * A level the reader would never match is refused rather than silently returning nothing:
     * an empty page reads as "the file is quiet", which is a different fact entirely.
     */
    public function testALevelTheReaderDoesNotRecognizeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Unknown log level: LOUD');

        LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::level => 'LOUD']));
    }

    public function testACursorBeforeTheStartOfTheFileIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::cursor => -1]));
    }

    public function testAnAnchorIsCarriedAsTheMomentOfTheEntry(): void
    {
        $dto = LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::anchorAtMs => 1788000000250]));

        $this->assertSame(1788000000250, $dto->anchorAtMs);
        $this->assertNull($dto->cursor);
        $this->assertSame(1788000000250, $dto->toArray()[LogsReadLinesActionDTO::anchorAtMs]);
    }

    public function testAnAnchorBeforeTheEpochIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([LogsReadLinesActionDTO::anchorAtMs => -1]));
    }

    /**
     * A cursor continues a page and an anchor opens one, so a request carrying both names two
     * different pages, and neither is picked for it (HIL-868).
     */
    public function testACursorAndAnAnchorTogetherAreRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        LogsReadLinesActionDTO::fromArray($this->live([
            LogsReadLinesActionDTO::cursor => 4096,
            LogsReadLinesActionDTO::anchorAtMs => 1788000000250,
        ]));
    }

    /**
     * Builds a minimal live read, overridden field by field.
     *
     * @param array<string, mixed> $overrides Fields to replace or add
     * @return array<string, mixed> Raw payload as the envelope carries it
     */
    private function live(array $overrides = []): array
    {
        return [SignalPayloadConstants::FIELD_DATA => $overrides + [
            LogsReadLinesActionDTO::nodeId => 'node-B',
            LogsReadLinesActionDTO::source => LogsReadLinesActionDTO::SOURCE_LIVE,
            LogsReadLinesActionDTO::stream => 'worker-0.log',
        ]];
    }
}
