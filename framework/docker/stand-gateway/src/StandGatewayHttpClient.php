<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\SocketException;

/**
 * StandGatewayHttpClient - a gateway connection that sends its answer the way a spec dictated.
 *
 * The framework's client answers a request the moment it is routed and, when the connection
 * is not kept alive, closes it the moment the answer is out. A declared {@see Behavior} bends
 * exactly those two moments: a delay holds the answer's bytes back, a cut shortens them and
 * closes, a hold keeps the connection open after them. HIL-921 made them two separate events
 * in the framework, which is what lets a subclass move one without touching the other.
 *
 * Every wait is a deadline the gateway's tick checks, never a pause inside the call: the
 * gateway is one process serving every connection, and a call that stopped to wait would
 * stall its neighbors. A deadline does not come before the declared moment and comes at most
 * one tick later.
 *
 * Three traps this class is shaped around:
 *
 * - The socket keeps being read while the answer waits. A peer's end of stream closes the
 *   connection only there, and a ready socket nobody reads keeps the gateway's non-blocking
 *   loop turning without end - so read() is not overridden.
 * - The delay counts from the moment the behavior was dictated, not from the first attempt to
 *   write, which would add a tick to every declared delay.
 * - An encrypted stream may take none or part of the bytes offered. The cut is made once, over
 *   the whole answer, and never over what is left after a partial write.
 */
final class StandGatewayHttpClient extends HttpClient
{
    /** @var ?Behavior Behavior dictated for the call being answered, null when none was */
    private ?Behavior $behavior = null;

    /** @var ?float Moment the answer may start to leave, as microtime(true); null when nothing is dictated */
    private ?float $releaseAt = null;

    /** @var bool Whether the dictated cut has already shortened the answer */
    private bool $cutApplied = false;

    /** @var ?float Moment the held connection is closed, as microtime(true); null while none is held */
    private ?float $closeAt = null;

    /**
     * Takes the behavior declared for the call being routed.
     *
     * Called while the request is routed, before the answer is queued, so the delay runs from
     * the call's arrival.
     *
     * @param Behavior $behavior Behavior to send the coming answer with
     */
    public function dictate(Behavior $behavior): void
    {
        $this->behavior = $behavior;
        $this->releaseAt = microtime(true) + $behavior->delayMs / TimeConstants::MS_PER_SECOND;
    }

    /**
     * Sends the queued answer, holding it back, cutting it or keeping the connection open as dictated.
     *
     * Before the delay is over nothing leaves and the buffer stays whole. Then a cut shortens
     * the answer once, to its headers and the first half of its body, and closes the
     * connection when that is out; a hold takes the closing over from the keep-alive policy.
     * Without a dictated behavior this is the framework's write.
     *
     * @throws SocketException If socket write fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function write(): void
    {
        if ($this->behavior !== null && $this->writeBuffer !== '') {
            if (microtime(true) < $this->releaseAt) {
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
     * Periodic tick: closes a held connection once its hold is over.
     *
     * The server drops the connection in the same tick, right after it writes.
     */
    public function onTick(): void
    {
        if ($this->closeAt !== null && microtime(true) >= $this->closeAt) {
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
     * A cut without a hold never gets here: the connection is already set to close. A status
     * or a delay leaves the connection's policy alone, so the next request on a kept-alive
     * connection is answered without levers.
     *
     * @throws SocketException When outbound write fails while handling a subsequent request
     * @throws InvalidFormatException When the request query string carries a non-string value
     * @throws HilosException When a pipelined follow-up request refuses to become a response
     */
    protected function onAfterOutboundDrained(): void
    {
        if ($this->behavior !== null && $this->behavior->holdMs > 0) {
            $this->closeAt = microtime(true) + $this->behavior->holdMs / TimeConstants::MS_PER_SECOND;

            return;
        }

        $this->behavior = null;
        $this->releaseAt = null;

        parent::onAfterOutboundDrained();
    }
}
