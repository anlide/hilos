<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\UnsupportedOperationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryInput;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Files\Upload\UploadMemoryReceipt;
use Hilos\Files\Upload\UploadMemoryReservation;
use Hilos\Hilos;
use Hilos\Socket\SocketException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Binary frame parsing preserves every byte and queues only complete frames (HIL-1527). */
final class WebSocketClientBinaryFrameTest extends TestCase
{
    private const string MASK_KEY = "\x37\x00\xfa\x81";

    private ?SignalRouter $previousSignalRouter = null;
    private ?EnvAccessor $previousEnv = null;

    /**
     * @return iterable<string, array{int, bool}> Length and masking variants across all header forms
     */
    public static function payloadCases(): iterable
    {
        foreach ([0, 1, 4, 5, 6, 7, 126, 65539] as $length) {
            foreach ([true, false] as $masked) {
                yield $length . ($masked ? ' masked' : ' unmasked') => [$length, $masked];
            }
        }
    }

    /**
     * @return iterable<string, array{int, bool, int}> Incomplete header, mask key and payload boundaries
     */
    public static function incompleteFrameCases(): iterable
    {
        foreach ([5 => 2, 126 => 4, 65539 => 10] as $length => $headerLength) {
            foreach ([true, false] as $masked) {
                $payloadStart = $headerLength + ($masked ? 4 : 0);
                $boundaries = [1, $headerLength - 1, $payloadStart, $payloadStart + 1, $payloadStart + $length - 1];
                if ($masked) {
                    $boundaries[] = $headerLength;
                    $boundaries[] = $headerLength + 1;
                    $boundaries[] = $payloadStart - 1;
                }
                foreach (array_unique($boundaries) as $boundary) {
                    yield $length . ($masked ? ' masked' : ' unmasked') . ' split at ' . $boundary => [$length, $masked, $boundary];
                }
            }
        }
    }

    /**
     * @param int $length Exact payload length in bytes
     * @param bool $masked Whether the client frame carries a masking key
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake or frame is invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    #[DataProvider('payloadCases')]
    public function testBinaryPayloadSurvivesEveryLengthAndMaskVariant(int $length, bool $masked): void
    {
        $payload = $this->binaryPayload($length);
        $probe = $this->handshakenProbe();

        $probe->feed($this->binaryFrame($payload, $masked));

        $this->assertNextBinaryPayload($probe, $payload);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param int $length Exact payload length in bytes
     * @param bool $masked Whether the client frame carries a masking key
     * @param int $boundary Number of bytes delivered before the rest of the frame
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake or frame is invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    #[DataProvider('incompleteFrameCases')]
    public function testIncompleteFrameWaitsForItsRemainingBytes(int $length, bool $masked, int $boundary): void
    {
        $payload = $this->binaryPayload($length);
        $wire = $this->binaryFrame($payload, $masked);
        $probe = $this->handshakenProbe();

        $probe->feed(substr($wire, 0, $boundary));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $probe->feed(substr($wire, $boundary));
        $this->assertNextBinaryPayload($probe, $payload);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $probe->feed('');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Mask alignment restarts for each frame, including the partial frame left after a batch.
     *
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake or frame is invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    public function testSeveralFramesInOneReadLeaveTheIncompleteLastFrameBuffered(): void
    {
        $probe = $this->handshakenProbe();
        $payloads = ['', "\x00\xff\x80\x01\x7f", $this->binaryPayload(65539), "\xfe\x00\x81"];
        $lastFrame = $this->binaryFrame($payloads[3], true);

        $probe->feed(
            $this->binaryFrame($payloads[0], true)
            . $this->binaryFrame($payloads[1], true)
            . $this->binaryFrame($payloads[2], false)
            . substr($lastFrame, 0, -1),
        );

        foreach (array_slice($payloads, 0, 3) as $payload) {
            $this->assertNextBinaryPayload($probe, $payload);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $probe->feed(substr($lastFrame, -1));
        $this->assertNextBinaryPayload($probe, $payloads[3]);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{int, bool}> Every header form with signed input */
    public static function boundedFrames(): iterable
    {
        foreach ([8, 126, 65536] as $rawBytes) {
            foreach ([true, false] as $masked) {
                yield $rawBytes . ($masked ? ' masked' : ' plain') => [$rawBytes, $masked];
            }
        }
    }

    /** @return iterable<string, array{int, bool}> Positive windows from one byte through every header form */
    public static function exactFrames(): iterable
    {
        yield 'one byte' => [1, true];
        yield 'three bytes' => [3, true];
        yield from self::boundedFrames();
    }

    /**
     * No raw tail has to arrive to reject a known signature without a grant.
     *
     * @param int $rawBytes Declared raw length beyond the signature
     * @param bool $masked Whether the early prefix needs unmasking
     */
    #[DataProvider('boundedFrames')]
    public function testBoundInputWithoutGrantFailsAsSoonAsItsSignatureArrives(int $rawBytes, bool $masked): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $payload = "\x04file" . str_repeat('x', $rawBytes);
        $wire = $this->binaryFrame($payload, $masked);
        $payloadStart = strlen($wire) - strlen($payload);
        // Every split of base/extended headers, mask and signature is harmless until the id is complete.
        for ($index = 0; $index < $payloadStart + 4; $index++) {
            $probe->feed($wire[$index]);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            $this->assertSame(0, $pool->getUsedBytes());
        }
        try {
            $probe->feed($wire[$payloadStart + 4]);
            $this->fail('The complete signature must reject before receiving file bytes');
        } catch (InvalidFrameException) {
            $this->assertSame('', $probe->inputBytes());
            $this->assertSame([], $sink->receipts);
            $this->assertSame(0, $pool->getUsedBytes());
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /**
     * @param int $rawBytes Declared raw length beyond the signature
     * @param bool $masked Whether the early prefix needs unmasking
     */
    #[DataProvider('boundedFrames')]
    public function testOverGrantIsRefusedBeforeTheIncompletePayloadCanAccumulate(int $rawBytes, bool $masked): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', $rawBytes);
        $payload = "\x04file" . str_repeat('x', $rawBytes);
        $wire = $this->binaryFrame($payload, $masked);
        try {
            $probe->feed(substr($wire, 0, strlen($wire) - $rawBytes));
            $this->fail('The declared physical frame exceeds its published raw window');
        } catch (InvalidFrameException) {
            $this->assertSame('', $probe->inputBytes());
            $this->assertSame(3, $pool->getUsedBytes());
            $this->assertSame([], $sink->receipts);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        }
        $sink->getUploadInput($probe->acceptKey)->cancel('file');
        $this->assertSame(0, $pool->getUsedBytes());
    }

    /**
     * @param int $rawBytes Exact granted raw length
     * @param bool $masked Whether the parser must unmask input
     */
    #[DataProvider('exactFrames')]
    public function testExactGrantAdmitsTheWholeFrameOnceAcrossEveryHeaderForm(int $rawBytes, bool $masked): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool($rawBytes, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $grant = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', $rawBytes);
        $payload = "\x04file" . $this->binaryPayload($rawBytes);
        $wire = $this->binaryFrame($payload, $masked);
        $payloadStart = strlen($wire) - strlen($payload);
        for ($index = 0; $index < $payloadStart + 5; $index++) {
            $probe->feed($wire[$index]);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            $this->assertSame($rawBytes, $pool->getUsedBytes());
        }
        $this->assertSame(0, $pool->getAvailableBytes($grant->requestId, $rawBytes));
        $probe->feed(substr($wire, $payloadStart + 5));
        $this->assertNextBinaryPayload($probe, $payload);
        $this->assertCount(1, $sink->receipts);
        $this->assertSame($rawBytes, $sink->receipts[0]->byteCount);
        $this->assertSame([$grant->id => $rawBytes], $sink->receipts[0]->reservationBytes);
        $pool->discardPending($sink->receipts[0]->id);
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testIncompleteFrameSurvivesCancelThenQueuesExactlyOnePendingReceipt(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(8, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $grant = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $wire = $this->binaryFrame("\x04fileabc", true);
        $probe->feed(substr($wire, 0, -2));
        $this->assertNotSame('', $probe->inputBytes());
        $this->assertSame(5, $pool->getAvailableBytes($grant->requestId, 3));
        $this->assertSame(8, $pool->getUsedBytes());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $sink->getUploadInput($probe->acceptKey)->cancel('file');
        $this->assertSame(3, $pool->getUsedBytes());
        $probe->feed('');
        $probe->feed(substr($wire, -2, 1));
        $this->assertSame(3, $pool->getUsedBytes());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $probe->feed(substr($wire, -1));
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($sink->frames[0], $signal->data);
        $this->assertSame("\x04fileabc", $signal->data->payload);
        $this->assertCount(1, $sink->receipts);
        $this->assertSame(3, $sink->receipts[0]->byteCount);
        $this->assertNull($sink->receipts[0]->channel);
        $probe->announceClose();
        $this->assertSame(3, $pool->getUsedBytes());
        $this->assertNotNull($pool->assign($sink->receipts[0]->id, 'worker:boot'));
        $pool->settle($sink->receipts[0]->id, 'worker:boot');
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testFragmentsAcrossWindowsBecomeOneWorkerReceiptWithoutReleasingBytes(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $first = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $pool->setLimitBytes(8);
        $second = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $probe->feed($this->binaryFrame("\x04fileab", true, false));
        $this->assertSame(8, $pool->getUsedBytes());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $continuation = $this->binaryFrame('cdefgh', true, true, 0);
        $probe->feed(substr($continuation, 0, -1));
        $this->assertSame(8, $pool->getUsedBytes());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $probe->feed(substr($continuation, -1));
        $this->assertNextBinaryPayload($probe, "\x04fileabcdefgh");
        $this->assertCount(1, $sink->receipts);
        $this->assertSame([$first->id => 3, $second->id => 5], $sink->receipts[0]->reservationBytes);
        $this->assertSame(8, $sink->receipts[0]->byteCount);
        $pool->assign($sink->receipts[0]->id, 'worker:boot');
        $pool->settle($sink->receipts[0]->id, 'worker:boot');
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testFragmentedOverrunDeletesRetainedRawBeforeDiscardingItsPendingParts(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(8, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 12);
        $probe->feed($this->binaryFrame("\x04fileab", true, false));
        $this->assertSame(8, $pool->getUsedBytes());
        $overrun = $this->binaryFrame('cdefghi', true, true, 0);
        try {
            // Just the continuation header/mask is enough; the whole message's id is already known.
            $probe->feed(substr($overrun, 0, 6));
            $this->fail('A continuation cannot evade the cumulative raw window');
        } catch (InvalidFrameException) {
            $this->assertSame('', $probe->inputBytes());
            $this->assertSame(6, $pool->getUsedBytes());
            $this->assertSame([], $sink->receipts);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        }
        $probe->announceClose();
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testCancelDuringFragmentedInputKeepsRawUntilARejectedContinuationDeletesIt(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(8, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $probe->feed($this->binaryFrame("\x04fileab", true, false));
        $sink->getUploadInput($probe->acceptKey)->cancel('file');
        $this->assertSame(2, $pool->getUsedBytes());
        try {
            $probe->feed($this->binaryFrame('c', true, true, 0));
            $this->fail('Canceled permission cannot admit another fragment');
        } catch (InvalidFrameException) {
            $this->assertSame('', $probe->inputBytes());
            $this->assertSame(0, $pool->getUsedBytes());
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /** @return iterable<string, array{bool}> Incomplete physical frame and complete fragment */
    public static function retainedInputCases(): iterable
    {
        yield 'incomplete frame' => [false];
        yield 'fragment' => [true];
    }

    /** @param bool $fragmented Whether input is in the fragmented accumulator */
    #[DataProvider('retainedInputCases')]
    public function testCloseDeletesRawInputBeforeReleasingItAndDoesNotTouchQueuedBytes(bool $fragmented): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(8, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $probe->feed($this->binaryFrame("\x04fileabc", false));
        $this->assertNextBinaryPayload($probe, "\x04fileabc");
        $wire = $this->binaryFrame("\x04filede", true, !$fragmented);
        $probe->feed($fragmented ? $wire : substr($wire, 0, -1));
        $sink->getUploadInput($probe->acceptKey)->cancel('file');
        $this->assertSame(5, $pool->getUsedBytes());
        $probe->announceClose();
        $this->assertSame('', $probe->inputBytes());
        $this->assertSame([$probe->acceptKey], $sink->closed);
        $this->assertSame(3, $pool->getUsedBytes());
        $pool->discardPending($sink->receipts[0]->id);
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testSignatureCanSpanFragmentsAndControlTrafficDoesNotNeedFileCredit(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 3);
        $probe->feed($this->binaryFrame("\x04f", true, false));
        $probe->feed($this->binaryFrame('il', true, false, 0));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $probe->feed($this->binaryFrame('eabc', true, true, 0));
        $this->assertNextBinaryPayload($probe, "\x04fileabc");
        $this->assertSame(3, $pool->getUsedBytes());
        $this->assertSame(0, $pool->getFreeBytes());
        $probe->acceptedActions[] = 'cancel_control';
        $probe->feed($this->binaryFrame('{"type":"action","action":"cancel_control","data":{}}', true, true, 1));
        $action = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($action);
        $this->assertSame(SignalTypeConstants::ACTION, $action->signalType->getType());
        $this->assertSame('cancel_control', $action->signalName->getName());
        $this->assertSame(3, $pool->getUsedBytes());
    }

    public function testRecreatedUploadCannotBorrowTheOldIncompleteMessagePermission(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(8, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $first = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 8);
        $probe->feed($this->binaryFrame("\x04fileab", true, false));
        $sink->getUploadInput($probe->acceptKey)->cancel('file');
        $second = $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 6);
        $this->assertNotSame($first->requestId, $second->requestId);
        try {
            $probe->feed($this->binaryFrame('c', true, true, 0));
            $this->fail('A continuation belongs to the original upload incarnation');
        } catch (InvalidFrameException) {
            $this->assertSame(6, $pool->getUsedBytes());
            $this->assertSame(6, $pool->getAvailableBytes($second->requestId, 0));
        }
        $probe->announceClose();
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testPriorPoolGenerationAndForeignUploadIdCannotSpendCurrentPermission(): void
    {
        $probe = $this->handshakenProbe();
        $oldPool = new UploadMemoryPool(3, 'node:old');
        $oldSink = new WebSocketBinaryMemorySink($oldPool);
        $old = $this->grantInput($oldPool, $oldSink, $probe->acceptKey, 'file', 3);
        $pool = new UploadMemoryPool(3, 'node:new');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $sink->getUploadInput($probe->acceptKey)->allow($old);
        try {
            $probe->feed($this->binaryFrame("\x04fileabc", true));
            $this->fail('An older master grant is not current permission');
        } catch (InvalidFrameException) {
            $this->assertSame(0, $pool->getUsedBytes());
        }
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 3);
        try {
            $probe->feed($this->binaryFrame("\x05otherabc", true));
            $this->fail('Another upload cannot spend this id\'s grant');
        } catch (InvalidFrameException) {
            $this->assertSame(3, $pool->getUsedBytes());
            $this->assertSame([], $sink->receipts);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        }
        $probe->announceClose();
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testBoundMalformedSignatureKeepsTheExistingPlainBinaryPath(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(0, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        foreach (["\x00raw", "\x02!?raw", "\x41" . str_repeat('x', 65), ''] as $payload) {
            $probe->feed($this->binaryFrame($payload, true));
            $this->assertNextBinaryPayload($probe, $payload);
            $this->assertSame([], $sink->receipts);
            $this->assertSame(0, $pool->getUsedBytes());
        }
    }

    public function testSeveralRealSocketsShareOnePoolAndCloseOnlyTheirOwnInput(): void
    {
        $first = $this->handshakenProbe();
        $second = $this->handshakenProbe();
        $pool = new UploadMemoryPool(6, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $first->setUploadMemoryPoolSink($sink);
        $second->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $first->acceptKey, 'file', 4);
        $this->grantInput($pool, $sink, $second->acceptKey, 'file', 4);
        $first->feed($this->binaryFrame("\x04fileabc", true, false));
        $second->feed($this->binaryFrame("\x04filede", true));
        $this->assertNextBinaryPayload($second, "\x04filede");
        $this->assertSame(6, $pool->getUsedBytes());
        $first->announceClose();
        $this->assertSame(2, $pool->getUsedBytes());
        $next = $pool->reserveNext();
        $this->assertNotNull($next);
        $this->assertSame($second->acceptKey, $next->acceptKey);
        $this->assertSame(2, $next->byteCount);
        $second->announceClose();
        $this->assertSame(2, $pool->getUsedBytes());
        $pool->discardPending($sink->receipts[0]->id);
        $this->assertSame(0, $pool->getUsedBytes());
    }

    public function testCloseReleasesUnusedGrantEvenWhenNoBinaryFrameWasEverRead(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $probe->setUploadMemoryPoolSink($sink);
        $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 3);
        $this->assertSame(3, $pool->getUsedBytes());
        $probe->announceClose();
        $this->assertSame(0, $pool->getUsedBytes());
        $this->assertSame([$probe->acceptKey], $sink->closed);
    }

    public function testPublishedGrantMustBelongToThisRealSocket(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $input = new UploadMemoryInput($pool, $probe->acceptKey);
        $pool->request('another-socket', 'file', 3);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $published = $pool->publish($hold->id);
        $this->assertNotNull($published);
        $this->expectException(LogicException::class);
        try {
            $input->allow($published);
        } finally {
            $this->assertSame(3, $pool->getUsedBytes());
            $this->assertSame(3, $pool->getAvailableBytes($published->requestId, 0));
        }
    }

    public function testHeldUnpublishedCapacityIsNeverBrowserPermission(): void
    {
        $probe = $this->handshakenProbe();
        $pool = new UploadMemoryPool(3, 'node:boot');
        $input = new UploadMemoryInput($pool, $probe->acceptKey);
        $pool->request($probe->acceptKey, 'file', 3);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $this->expectException(LogicException::class);
        try {
            $input->allow($hold);
        } finally {
            $this->assertSame(3, $pool->getUsedBytes());
            $this->assertSame(0, $pool->getAvailableBytes($hold->requestId, 0));
        }
    }

    public function testServerAcceptanceInjectsThePoolOwnerWithoutChangingTheProjectClientFactory(): void
    {
        $pool = new UploadMemoryPool(3, 'node:boot');
        $sink = new WebSocketBinaryMemorySink($pool);
        $server = new WebSocketBinaryMemoryServer('127.0.0.1', 0);
        $server->setUploadMemoryPoolSink($sink);
        $wireSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($wireSocket);
        try {
            $this->assertTrue($server->start());
            $this->assertTrue(socket_getsockname($server->getSocket(), $host, $port));
            $this->assertTrue(socket_connect($wireSocket, $host, $port));
            $probe = $server->acceptConnection();
            $this->assertInstanceOf(WebSocketClientTestProbe::class, $probe);
            $this->handshakenProbe($probe);
            // No direct client injection here: acceptConnection must have supplied it.
            $this->grantInput($pool, $sink, $probe->acceptKey, 'file', 3);
            $probe->feed($this->binaryFrame("\x04fileabc", true));
            $this->assertNextBinaryPayload($probe, "\x04fileabc");
            $this->assertCount(1, $sink->receipts);
            $this->assertSame(3, $pool->getUsedBytes());
            $server->dropClient($probe);
            $this->assertSame([], $server->getClients());
            $this->assertSame([$probe->acceptKey], $sink->closed);
            $this->assertSame(3, $pool->getUsedBytes());
            $pool->discardPending($sink->receipts[0]->id);
            $this->assertSame(0, $pool->getUsedBytes());
        } finally {
            foreach ($server->getClients() as $client) {
                $server->dropClient($client);
            }
            $server->stop();
            socket_close($wireSocket);
        }
    }

    /** Isolates the router queue and supplies the environment needed by the handshake. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        Hilos::$sr = new SignalRouter();
        Hilos::$env = new EnvAccessor();
    }

    /** Restores the facades replaced for each frame case. */
    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /**
     * @param int $length Exact payload length in bytes
     * @return string Binary fixture independent of its masked wire encoding
     */
    private function binaryPayload(int $length): string
    {
        return substr(str_repeat("\x00\xff\x80\x01\x7f\xc3\xa9\x5c", intdiv($length, 8) + 1), 0, $length);
    }

    /**
     * Encode fixtures byte by byte so expected payloads do not depend on string XOR.
     *
     * @param string $payload Original bytes to encode for the parser
     * @param bool $masked Whether to include a key and mask the fixture bytes
     * @param bool $fin Whether this physical frame completes the logical message
     * @param int $opcode Frame opcode, binary unless testing continuation/control
     * @return string Final binary frame using the shortest valid length header
     */
    private function binaryFrame(string $payload, bool $masked, bool $fin = true, int $opcode = 2): string
    {
        $length = strlen($payload);
        $maskBit = $masked ? 0x80 : 0;
        $firstByte = chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $header = $firstByte . chr($length | $maskBit);
        } elseif ($length <= 65535) {
            $header = $firstByte . chr(126 | $maskBit) . pack('n', $length);
        } else {
            $header = $firstByte . chr(127 | $maskBit) . pack('J', $length);
        }
        if (!$masked) {
            return $header . $payload;
        }

        $wirePayload = '';
        for ($offset = 0; $offset < $length; $offset++) {
            $wirePayload .= chr(ord($payload[$offset]) ^ ord(self::MASK_KEY[$offset % 4]));
        }

        return $header . self::MASK_KEY . $wirePayload;
    }

    /**
     * @param UploadMemoryPool $pool Sole ledger shared by all bound sockets
     * @param WebSocketBinaryMemorySink $sink Test-bound owner using production parser entrypoints
     * @param string $acceptKey Real handshaken socket
     * @param string $clientUploadId Upload to publish
     * @param int $desiredBytes Full declared size
     * @return UploadMemoryReservation Published window, possibly partial
     */
    private function grantInput(
        UploadMemoryPool $pool,
        WebSocketBinaryMemorySink $sink,
        string $acceptKey,
        string $clientUploadId,
        int $desiredBytes,
    ): UploadMemoryReservation {
        $pool->request($acceptKey, $clientUploadId, $desiredBytes);
        $held = $pool->reserveNext();
        $this->assertNotNull($held);
        $grant = $pool->publish($held->id);
        $this->assertNotNull($grant);
        $sink->getUploadInput($acceptKey)->allow($grant);

        return $grant;
    }

    /**
     * @param ?WebSocketClientTestProbe $probe An accepted client, or null for a socketless probe
     * @return WebSocketClientTestProbe Handshaken connection with an empty signal queue
     * @throws InvalidStateException When the socketless probe cannot be created
     * @throws SocketException When the handshake bytes are invalid
     * @throws AgentUnknownActionException When a frame names an unaccepted action
     * @throws UnsupportedOperationException When a frame reaches an unsupported signal branch
     */
    private function handshakenProbe(?WebSocketClientTestProbe $probe = null): WebSocketClientTestProbe
    {
        $probe ??= WebSocketClientTestProbe::createSocketless();
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->assertTrue($probe->handshakeDone());
        $handshake = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($handshake);
        $this->assertSame(SignalTypeConstants::HANDSHAKE, $handshake->signalType->getType());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        return $probe;
    }

    /**
     * @param WebSocketClientTestProbe $probe Connection that must have queued the frame
     * @param string $payload Expected original bytes before masking
     */
    private function assertNextBinaryPayload(WebSocketClientTestProbe $probe, string $payload): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::WEBSOCKET, $signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::FRAME_BINARY, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::FRAME_BINARY, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketFrameBinarySignalDTO::class, $signal->data);
        $this->assertSame($probe->acceptKey, $signal->data->acceptKey);
        $this->assertSame($payload, $signal->data->payload);
    }
}

/** Test-bound owner: actual parser and pool, without activating half of the new browser wire. */
final class WebSocketBinaryMemorySink implements UploadMemoryPoolSink
{
    /** @var list<WebSocketFrameBinarySignalDTO> Same payload objects the production parser queues */
    public array $frames = [];

    /** @var list<UploadMemoryReceipt> Pending receipts retained independently of socket input */
    public array $receipts = [];

    /** @var list<string> Closed sockets */
    public array $closed = [];

    /** @var array<string, UploadMemoryInput> Stable input per socket, all over one ledger */
    private array $inputs = [];

    /** @param UploadMemoryPool $pool Sole ledger shared by these socket inputs */
    public function __construct(private readonly UploadMemoryPool $pool)
    {
    }

    /**
     * @param string $acceptKey Real parser socket identity
     * @return UploadMemoryInput Stable connection admission metadata
     * @throws LogicException When the parser supplies an empty socket identity
     */
    public function getUploadInput(string $acceptKey): UploadMemoryInput
    {
        return $this->inputs[$acceptKey] ??= new UploadMemoryInput($this->pool, $acceptKey);
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $frame Same object later read from SignalRouter
     * @param UploadMemoryReceipt $receipt Pending receipt to bind at real worker choice
     */
    public function onUploadFrameQueued(WebSocketFrameBinarySignalDTO $frame, UploadMemoryReceipt $receipt): void
    {
        $this->frames[] = $frame;
        $this->receipts[] = $receipt;
    }

    /** @param string $acceptKey Closed socket whose input was already deleted */
    public function onUploadConnectionClosed(string $acceptKey): void
    {
        $this->closed[] = $acceptKey;
    }

    /**
     * @param WorkerClient $worker Reporting channel, unused in parser tests
     * @param int $memoryPoolBytes New ledger limit
     */
    public function onUploadSettings(WorkerClient $worker, int $memoryPoolBytes): void
    {
        $this->pool->setLimitBytes($memoryPoolBytes);
    }

    /**
     * @param WorkerClient $worker Worker reports are tested by WorkerUploadReceiptTest
     * @param list<string> $receiptIds Not expected on the WS-only fixture
     * @throws LogicException When the WS-only fixture receives a worker report
     */
    public function onUploadConsumed(WorkerClient $worker, array $receiptIds): void
    {
        throw new LogicException('WS parser fixture receives no worker reports');
    }
}

/** Real listening/accepting server; the project factory keeps its existing one-socket signature. */
final class WebSocketBinaryMemoryServer extends WebSocketServer
{
    /**
     * @param mixed $socket Accepted socket
     * @return WebSocketClientInterface Real client with the existing constructor
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new WebSocketClientTestProbe($socket);
    }

    /** The acceptance test needs no daemon startup work. */
    protected function onStart(): void
    {
    }
}
