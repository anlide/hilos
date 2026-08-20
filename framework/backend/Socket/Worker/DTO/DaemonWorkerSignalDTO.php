<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Daemon\MasterSignalSender;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * DaemonWorkerSignalDTO - a project signal travelling from the master to every worker of
 * this node.
 *
 * The frame behind {@see MasterSignalSender::sendToWorkers()}: the master writes one of
 * these to each worker link, and the worker hands it to {@see WorkerManager::onDaemonSignal()}.
 * It carries only what that hook takes - the signal name the project chose and the payload -
 * rather than a whole routed signal, because there is nothing left
 * to route once the frame is addressed: the destination is "every worker here", and the
 * source and type a {@see SignalDTO} carries would be answers to questions no receiver asks.
 *
 * The payload travels in the shared `{data, dataType}` envelope, so a worker rebuilds the
 * concrete payload class the project sent and degrades to {@see SignalData} when it cannot -
 * the same contract the agent path already gives.
 */
class DaemonWorkerSignalDTO extends WorkerDTO
{
    /** @var string Payload field key for the project-chosen signal name */
    public const string SIGNAL_NAME = 'signalName';

    // Message type (daemon -> worker); the master's broadcast door, distinct from the per-agent frame
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL;

    /**
     * Creates the daemon-to-workers signal frame.
     *
     * @param string $signalName Signal name the project addressed its workers under
     * @param SignalDataInterface $data Signal payload
     */
    public function __construct(
        public readonly string $signalName,
        public readonly SignalDataInterface $data,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return array_merge(
            [
                self::TYPE => $this->getType(),
                self::SIGNAL_NAME => $this->signalName,
            ],
            SignalDataEnvelope::encode($this->data),
        );
    }

    /**
     * Creates DTO from array.
     *
     * The name is required and the payload is not: a frame that says nothing about what it
     * carries still names what happened, and the envelope answers an absent payload with an
     * empty {@see SignalData} rather than refusing the frame.
     *
     * @param array<string, mixed> $data Source data (signalName, data, dataType)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no signal name, or a field holds another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            signalName: self::requireString($data, self::SIGNAL_NAME),
            data: SignalDataEnvelope::decode(
                self::optionalArray($data, SignalPayloadConstants::FIELD_DATA) ?? [],
                self::optionalString($data, SignalPayloadConstants::FIELD_DATA_TYPE),
            ),
        );
    }
}
