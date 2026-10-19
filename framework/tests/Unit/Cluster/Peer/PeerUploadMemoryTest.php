<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadAcceptedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadGrantedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReserveDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\Upload\DTO\UploadMemoryReservationData;
use Hilos\Files\Upload\UploadMemoryPeerSequence;
use Hilos\Files\Upload\UploadMemoryPool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Strict additive peer upload wire; protocol conservation is exercised separately below. */
final class PeerUploadMemoryTest extends TestCase
{
    public function testReceiptFrontierSkipsUnseenPoolAllocationsWithoutAcknowledgingThem(): void
    {
        $stream = new UploadMemoryPeerSequence(':', false);
        $stream->advance('boot:17');
        self::assertTrue($stream->hasPassed('boot:16'));
        self::assertFalse($stream->hasPassed('boot:23'));
        $stream->advance('boot:23');
        self::assertTrue($stream->hasPassed('boot:22'));
        try {
            $stream->advance('old-boot:24');
            self::fail('A different boot must not replace this stream');
        } catch (PeerTransportException) {
            self::assertFalse($stream->hasPassed('boot:24'));
        }
        $stream->advance('boot:24');
        self::assertTrue($stream->hasPassed('boot:24'));
    }

    /** @return iterable<string, array{string}> Invalid or out-of-order minted window identities */
    public static function invalidWindowIdentities(): iterable
    {
        foreach (['window', 'boot:window:0', 'boot:window:01', 'boot:window:-1', 'boot:window:1.0',
            'boot:window:999999999999999999999', 'boot:window:2', 'boot:window:1x'] as $id) {
            yield $id => [$id];
        }
    }

    /** @param string $id Identity that cannot start a real request() stream */
    #[DataProvider('invalidWindowIdentities')]
    public function testWindowFrontierRejectsMalformedAndSkippedIdsWithoutPoisoningTheNextRequest(string $id): void
    {
        $stream = new UploadMemoryPeerSequence(':window:', true);
        try {
            $stream->advance($id);
            self::fail('Malformed or skipped identity must not advance the stream');
        } catch (PeerTransportException) {
            self::assertFalse($stream->hasPassed('boot:window:1'));
        }
        $stream->advance('boot:window:1');
        self::assertTrue($stream->hasPassed('boot:window:1'));
        $stream->advance('boot:window:2');
        self::assertTrue($stream->hasPassed('boot:window:2'));
    }

    public function testAllControlFramesRoundTripWithoutExtraFields(): void
    {
        foreach ([
            new PeerUploadReserveDTO('boot:link:window', 65536),
            new PeerUploadGrantedDTO('boot:link:window', 7),
            new PeerUploadAcceptedDTO('boot:link:receipt'),
            new PeerUploadReleaseDTO('boot:link:window', 0),
        ] as $frame) {
            $parsed = PeerDTO::fromWire($frame->toJson());
            self::assertSame($frame::class, $parsed::class);
            self::assertSame($frame->toArray(), $parsed->toArray());
        }
    }

    public function testOptionalEnvelopeIsOmittedOnOldWireAndPreservedOnNewWire(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType('agent'),
            new SignalName('control'),
            new SignalData(['value' => 1]),
        );
        $old = new PeerSignalDTO('A', 'B', 'agent', null, $signal);
        self::assertSame([
            'type' => 'peer_signal', 'originNodeId' => 'A', 'targetNodeId' => 'B',
            'agentType' => 'agent', 'agentIndex' => null, 'signal' => $signal->toArray(),
        ], $old->toArray());
        self::assertNull(PeerSignalDTO::fromArray($old->toArray())->uploadReservation);

        $budgeted = new PeerSignalDTO('A', 'B', 'agent', null, $signal, new UploadMemoryReservationData('w', 'r', 9));
        $parsed = PeerDTO::fromWire($budgeted->toJson());
        self::assertInstanceOf(PeerSignalDTO::class, $parsed);
        self::assertSame(['reservationId' => 'w', 'receiptId' => 'r', 'bytes' => 9], $parsed->uploadReservation?->toArray());
    }

    public function testExternalHoldsShareCapacityWithoutEnteringBrowserFifo(): void
    {
        $pool = new UploadMemoryPool(9, 'boot');
        $pool->request('browser', 'local', 8);
        $peer = $pool->hold('link', 'remote', 12);
        self::assertNotNull($peer);
        self::assertSame(9, $pool->getUsedBytes());
        self::assertSame($peer, $pool->hold('link', 'remote', 12));
        self::assertNull($pool->reserveNext());
        $pool->releaseUnused($peer->id, 4);
        $published = $pool->publish($peer->id);
        self::assertNotNull($published);
        self::assertSame(4, $published->sendUpToBytes);
        $local = $pool->reserveNext();
        self::assertNotNull($local);
        self::assertSame('browser', $local->acceptKey);
        self::assertSame(5, $local->byteCount);
        self::assertNull($pool->reserveNext(), 'An external demand never leaks into browser FIFO');
    }

    public function testSplitPreservesOrderedContributionsAndSettlesEveryByteOnce(): void
    {
        $pool = new UploadMemoryPool(9, 'boot');
        $first = $pool->hold('socket', 'file', 9);
        self::assertNotNull($first);
        $pool->releaseUnused($first->id, 4);
        $pool->publish($first->id);
        $second = $pool->hold('socket', 'file', 9);
        self::assertNotNull($second);
        $pool->publish($second->id);
        $whole = $pool->acceptPending($first->requestId, 0, 9);
        self::assertNotNull($whole);
        $parts = $pool->splitPending($whole->id);
        self::assertSame([4, 5], array_column($parts, 'byteCount'));
        self::assertSame([[$first->id => 4], [$second->id => 5]], array_column($parts, 'reservationBytes'));
        self::assertSame(9, $pool->getUsedBytes());
        $pool->discardPending($whole->id);
        self::assertSame(9, $pool->getUsedBytes(), 'The replaced identity cannot release its portions');
        foreach ($parts as $part) {
            $pool->assign($part->id, 'peer-link');
            $pool->settle($part->id, 'old-peer-link');
            $pool->discardPending($part->id);
        }
        self::assertSame(9, $pool->getUsedBytes());
        foreach ($parts as $part) {
            $pool->settle($part->id, 'peer-link');
            $pool->settle($part->id, 'peer-link');
        }
        self::assertSame(0, $pool->getUsedBytes());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid control frames */
    public static function malformedFrames(): iterable
    {
        foreach (['peer_upload_reserve', 'peer_upload_granted', 'peer_upload_release'] as $type) {
            $field = $type === 'peer_upload_release' ? 'remainingBytes' : 'bytes';
            foreach ([null, false, '7', 7.0, [], -1] as $bad) {
                yield $type . ':' . get_debug_type($bad) => [['type' => $type, 'reservationId' => 'w', $field => $bad]];
            }
            yield $type . ':missing' => [['type' => $type, 'reservationId' => 'w']];
            yield $type . ':empty' => [['type' => $type, 'reservationId' => '', $field => 1]];
            yield $type . ':bad-id' => [['type' => $type, 'reservationId' => 7, $field => 1]];
            if ($field === 'bytes') {
                yield $type . ':zero' => [['type' => $type, 'reservationId' => 'w', $field => 0]];
            }
        }
        foreach ([null, false, '', 1, []] as $bad) {
            yield 'accepted:' . get_debug_type($bad) => [['type' => 'peer_upload_accepted', 'receiptId' => $bad]];
        }
        yield 'accepted:missing' => [['type' => 'peer_upload_accepted']];
    }

    /** @param array<string, mixed> $wire Invalid control frame */
    #[DataProvider('malformedFrames')]
    public function testMalformedControlFramesAreTransportRefusals(array $wire): void
    {
        $this->expectException(PeerTransportException::class);
        PeerDTO::fromWire(json_encode($wire, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return iterable<string, array{mixed}> Invalid optional envelopes */
    public static function malformedEnvelopes(): iterable
    {
        foreach ([null, false, 'w', [], ['reservationId' => 'w', 'receiptId' => 'r'],
            ['reservationId' => 'w', 'receiptId' => 'r', 'bytes' => '1'],
            ['reservationId' => 'w', 'receiptId' => 'r', 'bytes' => 0],
            ['reservationId' => '', 'receiptId' => 'r', 'bytes' => 1],
            ['reservationId' => 'w', 'receiptId' => '', 'bytes' => 1],
        ] as $index => $bad) {
            yield (string)$index => [$bad];
        }
    }

    /** @param mixed $envelope Invalid present optional field */
    #[DataProvider('malformedEnvelopes')]
    public function testMalformedEnvelopeCannotSilentlyBecomeAnUnbudgetedSignal(mixed $envelope): void
    {
        $this->expectException(PeerTransportException::class);
        PeerSignalDTO::fromArray([
            'originNodeId' => 'A', 'targetNodeId' => 'B', 'agentType' => 'agent',
            'signal' => [
                'signalSource' => ['source' => SignalSource::DAEMON], 'signalType' => 'agent',
                'signalName' => 'control', 'data' => [],
            ],
            'uploadReservation' => $envelope,
        ]);
    }
}
