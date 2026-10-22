<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Liveness;

/**
 * Liveness of one browser socket: when it is pinged, whether it is probably offline, when it is closed.
 *
 * Every moment is a reading of the socket's monotonic clock in milliseconds, handed in by the
 * socket; nothing here reads a clock, a time zone or the environment, so the same moments always
 * give the same answers. Every deadline of the silence counts from the last frame the browser
 * sent, and only such a frame moves it: a ping going out does not, and neither does anything this
 * side writes.
 *
 * One deadline is not the silence's: the check of the flag against the connection's runtime row
 * comes every ping interval from the handshake, and a frame does not move it. A socket the browser
 * keeps talking on never changes its flag, so a check that every frame pushed away would never
 * come for the very socket whose owner missed the flag going back to false.
 *
 * The state is private to one socket and dies with it, so it lives on this object rather than in
 * the runtime: no worker and no peer reads it. The socket carries out what the answers say - it
 * sends the ping, closes, or tells the connection's owner - and this object writes nothing.
 *
 * Once the close is due no other answer is given: a socket being closed is pinged no more,
 * changes no flag and is checked against no row.
 */
final class WebSocketLiveness
{
    /** Whether this socket was last settled as probably offline; false until its silence reaches that span */
    private(set) bool $probablyOffline = false;

    /** Moment of the last frame the browser sent, or of the handshake before the first one */
    private int $lastReceivedAtMs;

    /** Moment the next ping falls due */
    private int $nextPingAtMs;

    /** Moment the flag is next checked against the connection's runtime row */
    private int $nextReconcileAtMs;

    /**
     * @param WebSocketLivenessConfig $config Spans read once for this socket
     * @param int $handshakeAtMs Moment the handshake completed; silence counts from it until the first frame
     */
    public function __construct(
        private readonly WebSocketLivenessConfig $config,
        int $handshakeAtMs,
    ) {
        $this->lastReceivedAtMs = $handshakeAtMs;
        $this->nextPingAtMs = $handshakeAtMs + $config->pingIdleMs;
        $this->nextReconcileAtMs = $handshakeAtMs + $config->pingIntervalMs;
    }

    /**
     * Records a frame the browser sent: the silence ends at this moment.
     *
     * The next ping moves a whole idle span away, however the earlier schedule stood. The flag
     * is not touched here; {@see isProbablyOfflineTransitionDue()} answers for it on the next
     * tick, so both of its transitions are found in the one place that carries them out. The
     * check against the runtime row keeps its own schedule ({@see isReconcileDue()}).
     *
     * @param int $nowMs Monotonic moment the frame was taken whole
     */
    public function recordReceived(int $nowMs): void
    {
        $this->lastReceivedAtMs = $nowMs;
        $this->nextPingAtMs = $nowMs + $this->config->pingIdleMs;
    }

    /**
     * Records a ping handed to the socket at this moment.
     *
     * The next one is due an interval after the actual send, not after the moment this one fell
     * due, so a late tick sends a single ping instead of the ones it missed.
     *
     * @param int $nowMs Monotonic moment the ping was written
     */
    public function recordPingSent(int $nowMs): void
    {
        $this->nextPingAtMs = $nowMs + $this->config->pingIntervalMs;
    }

    /**
     * Records the flag checked against the connection's runtime row at this moment.
     *
     * The next check is an interval after this one, whatever the browser sends in between, and a
     * late tick checks once instead of catching up on the checks it missed.
     *
     * @param int $nowMs Monotonic moment of the check
     */
    public function recordReconciled(int $nowMs): void
    {
        $this->nextReconcileAtMs = $nowMs + $this->config->pingIntervalMs;
    }

    /**
     * Settles the flag on what the silence says at this moment.
     *
     * @param int $nowMs Monotonic moment of the tick
     */
    public function settleProbablyOffline(int $nowMs): void
    {
        $this->probablyOffline = $this->isProbablyOfflineAt($nowMs);
    }

    /**
     * @param int $nowMs Monotonic moment of the tick
     * @return bool Whether the silence has reached the close timeout
     */
    public function isCloseDue(int $nowMs): bool
    {
        return $nowMs - $this->lastReceivedAtMs >= $this->config->closeTimeoutMs;
    }

    /**
     * @param int $nowMs Monotonic moment of the tick
     * @return bool Whether a ping is due; never once the close is
     */
    public function isPingDue(int $nowMs): bool
    {
        return !$this->isCloseDue($nowMs) && $nowMs >= $this->nextPingAtMs;
    }

    /**
     * @param int $nowMs Monotonic moment of the tick
     * @return bool Whether the silence now disagrees with the settled flag; never once the close is due
     */
    public function isProbablyOfflineTransitionDue(int $nowMs): bool
    {
        return !$this->isCloseDue($nowMs) && $this->isProbablyOfflineAt($nowMs) !== $this->probablyOffline;
    }

    /**
     * @param int $nowMs Monotonic moment of the tick
     * @return bool Whether the flag is due to be checked against the connection's runtime row; never once the close is
     */
    public function isReconcileDue(int $nowMs): bool
    {
        return !$this->isCloseDue($nowMs) && $nowMs >= $this->nextReconcileAtMs;
    }

    /**
     * @param int $nowMs Monotonic moment of the tick
     * @return bool Whether the silence has reached the probably-offline span
     */
    private function isProbablyOfflineAt(int $nowMs): bool
    {
        return $nowMs - $this->lastReceivedAtMs >= $this->config->probablyOfflineMs;
    }
}
