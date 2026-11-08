<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Closure;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\SocketException;

/**
 * StandGatewayHttpClient - a gateway connection that sends its answer the way a spec dictated.
 *
 * The framework's client answers a request the moment it is routed and, when the connection
 * is not kept alive, closes it the moment the answer is out. A declared {@see Behavior} bends
 * exactly those two moments: a delay holds the answer's bytes back, a cut shortens them and
 * closes, a hold keeps the connection open after them. HIL-921 made them two separate events
 * in the framework, which is what lets a subclass move one without touching the other. The
 * fourth bend moves the first moment too: an answer that awaits its release is held until a
 * spec released one answer of the call's route and key, and with a delay as well it leaves
 * when both are over.
 *
 * Delay and hold use one-shot monotonic timers; a release mutation wakes only its pair's
 * subscribers. These notifications enqueue the same guarded client portion as native I/O.
 * Pending output alone never makes a held answer runnable.
 *
 * The boundaries this class preserves:
 *
 * - The socket keeps being read while the answer waits. A peer's end of stream closes the
 *   connection only there, and a ready socket nobody reads keeps the gateway's non-blocking
 *   loop turning without end - so read() is not overridden.
 * - The delay counts from the moment the behavior was dictated, not from the first attempt to
 *   write, which would extend every declared delay.
 * - A release is spent once, by the first write that finds it; the writes after it do not ask
 *   for another, or one held answer would spend the releases of its neighbors on the pair.
 * - An encrypted stream may take none or part of the bytes offered. The cut is made once, over
 *   the whole answer, and never over what is left after a partial write.
 */
final class StandGatewayHttpClient extends HttpClient
{
    /** @var ?Behavior Behavior dictated for the call being answered, null when none was */
    private ?Behavior $behavior = null;

    /** @var ?float Monotonic seconds when the answer may leave; null without a behavior */
    private ?float $releaseAt = null;

    /** @var bool Whether the dictated cut has already shortened the answer */
    private bool $cutApplied = false;

    /** @var ?float Monotonic seconds when the held connection closes; null before its actual drain */
    private ?float $closeAt = null;

    /** @var ?string Provider route of the call being answered, null when nothing is dictated */
    private ?string $path = null;

    /** @var ?string Value of the call the dictated behavior was taken by, null when nothing is dictated */
    private ?string $key = null;

    /** @var bool Whether a spec's release has already let the held answer out */
    private bool $released = false;

    private ?EventLoopTimer $waitTimer = null;

    /** @var ?Closure(): void Removes the current route/key subscription */
    private ?Closure $releaseSubscription = null;

    /** @var bool Whether arrival, rebinding or a release mutation warrants a token lookup */
    private bool $releasePending = true;

    /** @var int Invalidates callbacks belonging to an earlier binding or behavior */
    private int $waitGeneration = 0;

    /**
     * Rebinds native I/O and restores only this connection's current waits.
     *
     * @param EventLoop $loop Gateway's native base and ready queue
     * @param Closure(): void $wake Existing guarded read/tick/write/drop portion
     * @throws SocketTlsHandshakeException When TLS is rebound to another native base
     * @throws EventLoopException When the current deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        parent::attachEventLoop($loop, $wake);
        if ($this->getSocket() === null) {
            return;
        }

        $this->waitTimer = $loop->createTimer($this->markReady(...));
        $this->releasePending = true;
        $this->scheduleWaits();
    }

    /** Cancels native and queued waits before I/O detaches; retains the accepted answer for rebinding. */
    public function detachEventLoop(): void
    {
        $this->cancelReleaseSubscription();
        $this->waitTimer?->close();
        $this->waitTimer = null;
        parent::detachEventLoop();
    }

    /**
     * Takes the behavior declared for the call being routed.
     *
     * Called while the request is routed, before the answer is queued, so the delay runs from
     * the call's arrival. The route and the key are the pair the behavior was taken by, and so
     * the pair a release for an answer that awaits one is looked for under.
     *
     * @param Behavior $behavior Behavior to send the coming answer with
     * @param string $path Provider route that was called
     * @param string $key Value of the call the behavior was taken by
     * @throws EventLoopException When the delay cannot be armed
     */
    public function dictate(Behavior $behavior, string $path, string $key): void
    {
        $this->cancelReleaseSubscription();
        $this->behavior = $behavior;
        $this->releaseAt = self::monotonicSeconds() + $behavior->delayMs / TimeConstants::MS_PER_SECOND;
        $this->path = $path;
        $this->key = $key;
        $this->cutApplied = false;
        $this->released = false;
        $this->releasePending = true;
        $this->scheduleWaits();
    }

    /**
     * Sends the queued answer, holding it back, cutting it or keeping the connection open as dictated.
     *
     * Before a release came for an answer that awaits one, and before the delay is over,
     * nothing leaves and the buffer stays whole. Then a cut shortens the answer once, to its
     * headers and the first half of its body, and closes the connection when that is out; a
     * hold takes the closing over from the keep-alive policy. Without a dictated behavior this
     * is the framework's write.
     *
     * @throws SocketException If socket write fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function write(): void
    {
        if ($this->behavior !== null && $this->writeBuffer !== '') {
            if ($this->behavior->awaitRelease && !$this->released) {
                if ($this->waitTimer !== null && !$this->releasePending) {
                    return;
                }
                $this->releasePending = false;
                if (!Store::takeRelease($this->path, $this->key)) {
                    return;
                }
                $this->released = true;
                $this->cancelReleaseSubscription();
            }

            if (self::monotonicSeconds() < $this->releaseAt) {
                return;
            }

            if ($this->behavior->cut && !$this->cutApplied) {
                $bodyStart = strpos($this->writeBuffer, HttpConstants::HTTP_DELIMITER) + strlen(HttpConstants::HTTP_DELIMITER);
                $this->writeBuffer = substr($this->writeBuffer, 0, $bodyStart + intdiv(strlen($this->writeBuffer) - $bodyStart, 2));
                $this->closeWhenOutputDrained = true;
                $this->cutApplied = true;
            }

            // The hold closes the connection itself, whatever the request asked for.
            if ($this->behavior->holdMs > 0) {
                $this->closeWhenOutputDrained = false;
            }
        }

        parent::write();
    }

    /**
     * Checks the held connection after its timer wakes the existing guarded client portion.
     * Isolated unbound fixtures may also call this directly.
     */
    public function onTick(): void
    {
        if ($this->closeAt !== null && self::monotonicSeconds() >= $this->closeAt) {
            $this->markShouldClose();
        }
    }

    /**
     * Parses the next request, unless the connection is held - then whatever arrived is thrown away.
     *
     * A held connection answers nothing more: it only waits to be closed.
     *
     * @throws SocketException When outbound write fails during request handling
     * @throws InvalidFormatException When the request query string carries a non-string value
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     */
    protected function processReadBuffer(): void
    {
        if ($this->closeAt !== null) {
            $this->readBuffer = '';

            return;
        }

        parent::processReadBuffer();
    }

    /**
     * Once the answer is out, starts the dictated hold, or forgets the behavior and reads the next request.
     *
     * A cut without a hold never gets here: the connection is already set to close. A status,
     * a delay or a release leaves the connection's policy alone, so the next request on a
     * kept-alive connection is answered without levers.
     *
     * @throws SocketException When outbound write fails while handling a subsequent request
     * @throws InvalidFormatException When the request query string carries a non-string value
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     * @throws EventLoopException When the hold cannot be armed
     */
    protected function onAfterOutboundDrained(): void
    {
        $this->cancelReleaseSubscription();
        if ($this->behavior !== null && $this->behavior->holdMs > 0) {
            $this->closeAt = self::monotonicSeconds() + $this->behavior->holdMs / TimeConstants::MS_PER_SECOND;
            $this->waitTimer?->armAt($this->closeAt);

            return;
        }

        $this->waitTimer?->armAt(null);
        $this->behavior = null;
        $this->releaseAt = null;
        $this->path = null;
        $this->key = null;
        $this->released = false;
        $this->cutApplied = false;

        parent::onAfterOutboundDrained();
    }

    /** @throws EventLoopException When the current monotonic deadline cannot be armed */
    private function scheduleWaits(): void
    {
        if ($this->waitTimer === null) {
            return;
        }

        if ($this->behavior?->awaitRelease && !$this->released && $this->releaseSubscription === null) {
            $generation = $this->waitGeneration;
            $this->releaseSubscription = Store::subscribeRelease($this->path, $this->key, function () use ($generation): void {
                if ($generation === $this->waitGeneration) {
                    $this->releasePending = true;
                    $this->markReady();
                }
            });
        }

        $this->waitTimer->armAt($this->closeAt ?? (
            $this->releaseAt !== null && $this->releaseAt > self::monotonicSeconds() ? $this->releaseAt : null
        ));
    }

    private function cancelReleaseSubscription(): void
    {
        ++$this->waitGeneration;
        $this->releaseSubscription?->__invoke();
        $this->releaseSubscription = null;
    }

    /** @return float Process-local monotonic seconds, independent of Store's epoch fields */
    private static function monotonicSeconds(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }
}
