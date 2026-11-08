<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Closure;
use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\BrowserDeliveryTarget;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Hilos;
use Throwable;

/** One local FIFO obligation; its transport candidates are private to the accepting master. */
final class LocalClientSignalBatch
{
    public readonly SignalDTO $signal;

    private bool $started = false;

    private bool $complete = false;

    /**
     * @param SignalName $name Shared browser signal name
     * @param SignalDataInterface $data Shared application payload
     * @param Generator<int, ?int> $targets Finite scalar transport slots, never socket objects
     * @param Closure(int): ?BrowserDeliveryTarget $resolve Weak transport owner and original admission rule
     * @param ?Generator<int, void> $preparation Original rule's private preparation, completed before any target visit
     * @throws InvalidArgumentException When the shared name is empty
     */
    public function __construct(
        SignalName $name,
        private readonly SignalDataInterface $data,
        private Generator $targets,
        private readonly Closure $resolve,
        private ?Generator $preparation = null,
    ) {
        $this->signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::WS_USER),
            $name, new WebSocketSignalData($data), Hilos::$ac?->captureSignalMeta() ?? []);
    }

    /** @return bool Whether the finite cohort has been consumed, without advancing its cursor */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return ?BrowserDeliveryTarget One original admitted target, absent for a preparation step or empty/filtered slot
     * @throws Throwable When original preparation, candidate source or admission callback fails
     */
    public function consumeTarget(): ?BrowserDeliveryTarget
    {
        if ($this->complete) {
            return null;
        }
        if ($this->preparation !== null) {
            if ($this->preparation->valid()) {
                $this->preparation->next();
            } else {
                $this->preparation = null;
            }
            return null;
        }
        if ($this->started) {
            $this->targets->next();
        } else {
            $this->started = true;
        }
        if (!$this->targets->valid()) {
            $this->complete = true;
            return null;
        }
        $slot = $this->targets->current();
        return $slot === null ? null : ($this->resolve)($slot);
    }

    /**
     * Synchronous consuming compatibility only. The master consumes the batch itself instead,
     * retaining its finite FIFO slot until every physical visit and send has settled.
     *
     * @return ?SignalDTO Next original addressed notification, absent at the finite end
     * @throws InvalidArgumentException When the shared signal name is empty
     * @throws Throwable When original preparation, candidate source or admission callback fails
     */
    public function consumeSignal(): ?SignalDTO
    {
        while (!$this->complete) {
            $client = $this->consumeTarget()?->getClient();
            if ($client !== null) {
                return new SignalDTO($this->signal->signalSource, $this->signal->signalType, $this->signal->signalName,
                    new WebSocketSignalData($this->data, targetAcceptKey: $client->acceptKey), $this->signal->meta);
            }
        }
        return null;
    }
}
