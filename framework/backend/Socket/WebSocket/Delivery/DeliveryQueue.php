<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Generator;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Client\WebSocketClient;

/**
 * Outgoing queue of one TCP connection: application text waits out the delivery window, coalescing through
 * {@see DeliveryCoalescer}, and all of it is held while a page subscription is incomplete.
 */
final class DeliveryQueue
{
    /** Opening of the batch drain() wraps two or more envelopes in, written once for packing and counting. */
    private const string BATCH_OPEN = '{"' . SignalPayloadConstants::FIELD_TYPE . '":"' . SignalTypeConstants::SIGNAL_BATCH
        . '","' . SignalPayloadConstants::FIELD_DATA . '":{"' . SignalPayloadConstants::FIELD_FRAMES . '":[';
    private const string BATCH_SEPARATOR = ',';
    private const string BATCH_CLOSE = ']}}';

    private DeliveryCoalescer $coalescer;
    private int $windowMs;
    private ?int $lastDeliveryAt = null;
    private ?int $nextDueAt = null;
    private ?int $immediateUntil = null;
    private bool $urgent = false;
    private ?string $subscriptionId = null;

    /** @var array<string, true> Master-minted pending update tokens */
    private array $updates = [];

    /**
     * Transport bytes of the packages an opaque frame has already closed, frame headers included.
     * No merge reaches behind an opaque frame, so they stay as counted until the frames go.
     */
    private int $closedPackageBytes = 0;

    /** Survivor text bytes inside the closed packages; the rest of the coalescer's is the open run. */
    private int $closedTextBytes = 0;

    /** Survivors inside the closed packages; the rest of the coalescer's are the open run. */
    private int $closedFrameCount = 0;

    private DeliveryCleanup $cleanup;

    /** @var ?Generator<int, ?string> Original packet; later enqueues cannot change its captured survivors */
    private ?Generator $packing = null;

    /** Transport bytes still owned by packing, transferred once when a complete package leaves. */
    private int $packingBytes = 0;

    /**
     * @param int $windowMs Effective positive delivery window in milliseconds
     * @param ?DeliveryCleanup $cleanup Master's guarded owner, absent for standalone consuming queues
     * @throws InvalidFormatException When the window is not positive
     */
    public function __construct(int $windowMs = DeliverySettingsCatalog::DEFAULT_WINDOW_MS, ?DeliveryCleanup $cleanup = null)
    {
        $this->setWindowMs($windowMs);
        $this->cleanup = $cleanup ?? new DeliveryCleanup();
        $this->coalescer = new DeliveryCoalescer($this->cleanup);
    }

    /** @param DeliveryCleanup $cleanup Master's guarded cleanup owner */
    public function setCleanup(DeliveryCleanup $cleanup): void
    {
        $this->cleanup = $cleanup;
        $this->coalescer->setCleanup($cleanup);
    }

    /**
     * An already opened deadline or grace period keeps the value it was opened with.
     *
     * @param int $windowMs Effective positive delivery window in milliseconds
     * @throws InvalidFormatException When the window is not positive
     */
    public function setWindowMs(int $windowMs): void
    {
        if ($windowMs <= 0) {
            throw new InvalidFormatException('WebSocket delivery window must be positive');
        }
        $this->windowMs = $windowMs;
    }

    /** @return int Effective window in milliseconds */
    public function getWindowMs(): int
    {
        return $this->windowMs;
    }

    /** @return ?int Fixed monotonic deadline from the last nonempty delivery */
    public function getNextDueAt(): ?int
    {
        return $this->nextDueAt;
    }

    /**
     * An admitted packet continues through a later hold; a new packet follows drainPortion's admission.
     * Reading this deadline neither captures a packet nor opens a delivery window.
     *
     * @param int $nowMs Caller-supplied monotonic milliseconds
     * @param bool $force Whether graceful close is releasing completed work
     * @return ?int Actionable packing/admission moment, absent for an empty or held queue
     */
    public function nextWorkAt(int $nowMs, bool $force = false): ?int
    {
        if ($this->packing !== null) {
            return $nowMs;
        }
        if ($this->isHeld() || !$this->coalescer->hasFrames()) {
            return null;
        }
        if (!$force && !$this->urgent
            && ($this->immediateUntil === null || $nowMs >= $this->immediateUntil)
            && $this->lastDeliveryAt !== null && $nowMs < $this->nextDueAt
        ) {
            return $this->nextDueAt;
        }

        return $nowMs;
    }

    /** @return bool Whether an incomplete subscription blocks all application text */
    public function isHeld(): bool
    {
        return $this->subscriptionId !== null;
    }

    /** @return bool Whether application text is waiting */
    public function hasFrames(): bool
    {
        return $this->packing !== null || $this->coalescer->hasFrames();
    }

    /** @return bool Whether the original packet has runnable packing work, independent of a later subscription hold */
    public function hasPendingPacking(): bool
    {
        return $this->packing !== null;
    }

    /**
     * Counted as the queue changes, so asking walks nothing: the closed packages plus the open run
     * measured from the coalescer's survivors.
     *
     * @return int Transport bytes drain() would hand the socket for everything held now, a held
     *             subscription included, with batch wrappers, separators and frame headers
     */
    public function getPendingBytes(): int
    {
        return $this->packingBytes + $this->closedPackageBytes + self::packageBytes(
            $this->coalescer->getFrameCount() - $this->closedFrameCount,
            $this->coalescer->getHeldBytes() - $this->closedTextBytes,
        );
    }

    /**
     * @param DeliveryFrame $frame Prepared immutable broadcast frame
     * @param int $nowMs Caller-supplied monotonic milliseconds
     * @throws InvalidFormatException When a merged envelope cannot be encoded
     */
    public function enqueue(DeliveryFrame $frame, int $nowMs): void
    {
        $this->coalescer->append(
            $frame,
            barrier: $this->isHeld() && $frame->getType() === SignalTypeConstants::PAGE_RESPONSE,
        );
        if (self::standsAlone($frame)) {
            $this->closeOpenRun($frame);
        }
        if ($frame->isUrgent()) {
            $this->acceptRequest($nowMs);
        }
    }

    /**
     * Only validated, accepted browser requests call this door; it affects this queue alone.
     *
     * @param int $nowMs Caller-supplied monotonic milliseconds
     */
    public function acceptRequest(int $nowMs): void
    {
        $this->urgent = $this->hasFrames();
        $this->immediateUntil = $nowMs + $this->windowMs;
    }

    /**
     * Replacing an unfinished subscription discards its incomplete packet.
     *
     * @param string $deliveryId Nonempty master-minted token, never browser input
     * @param int $nowMs Caller-supplied monotonic milliseconds
     * @throws InvalidFormatException When the token is empty
     */
    public function beginSubscription(string $deliveryId, int $nowMs): void
    {
        self::requireDeliveryId($deliveryId);
        if ($this->isHeld()) {
            $this->coalescer->clear();
            $this->forgetClosedPackages();
        }
        $this->cleanup->retire($this->updates);
        $this->subscriptionId = $deliveryId;
        $this->acceptRequest($nowMs);
    }

    /**
     * Updates are correlated without holding unrelated application delivery.
     *
     * @param string $deliveryId Nonempty master-minted update token
     * @param int $nowMs Caller-supplied monotonic milliseconds
     * @throws InvalidFormatException When the token is empty
     */
    public function beginUpdate(string $deliveryId, int $nowMs): void
    {
        self::requireDeliveryId($deliveryId);
        $this->updates[$deliveryId] = true;
        $this->acceptRequest($nowMs);
    }

    /**
     * Old, canceled and duplicate completions cannot release a newer hold or reopen grace.
     *
     * @param string $deliveryId Token from the internal completion signal
     * @param int $nowMs Completion time, also the origin of delayed-reply grace
     */
    public function completeDelivery(string $deliveryId, int $nowMs): void
    {
        if ($this->subscriptionId === $deliveryId) {
            $this->subscriptionId = null;
        } elseif (isset($this->updates[$deliveryId])) {
            unset($this->updates[$deliveryId]);
        } else {
            return;
        }
        $this->acceptRequest($nowMs);
    }

    /**
     * A canceled subscription's packet is incomplete and must never be delivered as success.
     *
     * @param string $deliveryId Subscription or update token being abandoned
     */
    public function cancelDelivery(string $deliveryId): void
    {
        unset($this->updates[$deliveryId]);
        if ($this->subscriptionId === $deliveryId) {
            $this->subscriptionId = null;
            $this->coalescer->clear();
            $this->forgetClosedPackages();
            $this->urgent = false;
            $this->immediateUntil = null;
        }
    }

    /** Invalidates every pending completion while preserving completed, unrelated application text. */
    public function cancelPending(): void
    {
        if ($this->subscriptionId !== null) {
            $this->cancelDelivery($this->subscriptionId);
        }
        $this->cleanup->retire($this->updates);
    }

    /**
     * Quiet first delivery happens here, never during enqueue. Even forced shutdown cannot
     * release an incomplete subscription. Opaque text and prebuilt batches split packages.
     *
     * @param int $nowMs Caller-supplied monotonic milliseconds
     * @param bool $force Drain completed work before a graceful transport close
     * @return list<string> Ready text packages, empty when held, not due, or empty
     */
    public function drain(int $nowMs, bool $force = false): array
    {
        return $this->drainPortion($nowMs, PHP_INT_MAX, $force);
    }

    /**
     * Captures one due packet before advancing it. Deadlines and urgency move at that original
     * decision, so later input keeps its own cadence and cannot join a half-packed batch.
     * Each step visits one survivor or emits one COMPLETE package. String bytes, PHP buffer
     * growth and eventual decoded-frame destruction remain separate costs.
     *
     * @param int $nowMs Caller-supplied monotonic milliseconds of this drain decision
     * @param int $steps Maximum survivor or package transitions
     * @param bool $force Drain completed work before graceful close
     * @return list<string> Complete packages, at most one per step; a batch never leaves partially
     */
    public function drainPortion(int $nowMs, int $steps, bool $force = false): array
    {
        if ($steps <= 0) {
            return [];
        }
        if ($this->packing === null) {
            if ($this->isHeld() || !$this->coalescer->hasFrames()) {
                return [];
            }
            if (!$force && !$this->urgent
                && ($this->immediateUntil === null || $nowMs >= $this->immediateUntil)
                && $this->lastDeliveryAt !== null && $nowMs < $this->nextDueAt
            ) {
                return [];
            }
            $this->packingBytes = $this->getPendingBytes();
            $this->packing = self::packetDelivery($this->coalescer->takeFrames(), $this->cleanup);
            $this->forgetClosedPackages();
            $this->urgent = false;
            $this->lastDeliveryAt = $nowMs;
            $this->nextDueAt = $nowMs + $this->windowMs;
        }

        $packages = [];
        $this->packing->valid();
        for ($step = 0; $step < $steps && $this->packing !== null; $step++) {
            $this->packing->next();
            if (!$this->packing->valid()) {
                $this->packing = null;
                break;
            }
            $package = $this->packing->current();
            if ($package !== null) {
                $packages[] = $package;
                $this->packingBytes -= WebSocketClient::frameHeaderBytes(strlen($package)) + strlen($package);
            }
        }

        return $packages;
    }

    /** Discard all socket-local delivery state; the effective setting remains configured. */
    public function close(): void
    {
        $this->packing = null;
        $this->packingBytes = 0;
        $this->coalescer->clear();
        $this->forgetClosedPackages();
        $this->subscriptionId = null;
        $this->cleanup->retire($this->updates);
        $this->lastDeliveryAt = null;
        $this->nextDueAt = null;
        $this->immediateUntil = null;
        $this->urgent = false;
    }

    /**
     * @param string $deliveryId Token owned by the transport integration
     * @throws InvalidFormatException When the token is empty
     */
    private static function requireDeliveryId(string $deliveryId): void
    {
        if ($deliveryId === '') {
            throw new InvalidFormatException('Delivery token must be nonempty');
        }
    }

    /**
     * @param DeliveryFrame $frame Survivor about to be packed
     * @return bool Whether it is a package of its own: opaque text or a batch already built
     */
    private static function standsAlone(DeliveryFrame $frame): bool
    {
        return $frame->getType() === null || $frame->getType() === SignalTypeConstants::SIGNAL_BATCH;
    }

    /**
     * @param array<int, DeliveryFrame> $frames Original sparse survivors, transferred without array_values()
     * @param DeliveryCleanup $cleanup Existing owner of the detached packet after packing or cancellation
     * @return Generator<int, ?string> Effect-free admission, then one survivor transition or complete package
     */
    private static function packetDelivery(array $frames, DeliveryCleanup $cleanup): Generator
    {
        $text = null;
        $count = 0;
        try {
            yield null;
            foreach ($frames as $frame) {
                if (self::standsAlone($frame)) {
                    if ($text !== null) {
                        if ($count > 1) {
                            $text .= self::BATCH_CLOSE;
                        }
                        yield $text;
                        $text = null;
                        $count = 0;
                    }
                    yield $frame->json;
                } else {
                    if ($count === 0) {
                        $text = $frame->json;
                    } elseif ($count === 1) {
                        $text = self::BATCH_OPEN . $text . self::BATCH_SEPARATOR . $frame->json;
                    } else {
                        $text .= self::BATCH_SEPARATOR;
                        $text .= $frame->json;
                    }
                    $count++;
                    yield null;
                }
            }
            if ($text !== null) {
                if ($count > 1) {
                    $text .= self::BATCH_CLOSE;
                }
                yield $text;
            }
        } finally {
            $cleanup->retire($frames);
        }
    }

    /**
     * @param int $frames Survivors drain() packs together, or the one that stands alone
     * @param int $textBytes Their prepared text bytes
     * @return int Transport bytes of the package drain() makes of them, its frame header included; none for no survivor
     */
    private static function packageBytes(int $frames, int $textBytes): int
    {
        if ($frames === 0) {
            return 0;
        }
        $packageBytes = $frames === 1
            ? $textBytes
            : strlen(self::BATCH_OPEN) + $textBytes + ($frames - 1) * strlen(self::BATCH_SEPARATOR) + strlen(self::BATCH_CLOSE);

        return WebSocketClient::frameHeaderBytes($packageBytes) + $packageBytes;
    }

    /**
     * Closes the run before an opaque frame just appended, and the frame's own package after it.
     *
     * @param DeliveryFrame $frame The coalescer's last survivor, appended as a boundary
     */
    private function closeOpenRun(DeliveryFrame $frame): void
    {
        $textBytes = strlen($frame->json);
        $this->closedPackageBytes += self::packageBytes(
            $this->coalescer->getFrameCount() - $this->closedFrameCount - 1,
            $this->coalescer->getHeldBytes() - $this->closedTextBytes - $textBytes,
        ) + self::packageBytes(1, $textBytes);
        $this->closedTextBytes = $this->coalescer->getHeldBytes();
        $this->closedFrameCount = $this->coalescer->getFrameCount();
    }

    /** The coalescer has just let every survivor go, so no package is left to count. */
    private function forgetClosedPackages(): void
    {
        $this->closedPackageBytes = 0;
        $this->closedTextBytes = 0;
        $this->closedFrameCount = 0;
    }
}
