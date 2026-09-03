<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;

/**
 * Rotations page → {@see LogStoreAgent} payload for the logs_agent_takeout_undo signal (HIL-759).
 *
 * The withdrawal as it travels to the node that owns the archive: the batch the browser named,
 * plus the three fields that let the answer find its way back. The page does not answer this
 * action itself ({@see AbstractHilosLogsRotationsPage::onAction()} defers it), so the owner is the
 * last step and needs the accept key, the action name and the request id the browser minted.
 *
 * {@see $nodeId} is also the address: the owner declares it under
 * {@see AgentSignalConfigKey::NODE_FIELD}, and the router turns a foreign id into a delivery over
 * the peer channel. An empty id is this node, which is what a single-node install always sends.
 *
 * No user id rides along, and that is the difference from the confirmation's frame: writing the
 * marker records who said the batch was carried off, while withdrawing it leaves no fact behind
 * to attribute. Carrying an id nothing would be written from would be asking the page a question
 * for the sake of the symmetry.
 */
final class LogsTakeoutUndoSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: accept key of the connection waiting for the withdrawal. */
    public const string acceptKey = 'acceptKey';

    /** Payload key: name of the action the reply acknowledges. */
    public const string action = 'action';

    /** Payload key: request id the browser minted, quoted back on the ack, absent when untracked. */
    public const string requestId = 'requestId';

    /**
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @param int $batchTimestamp Unix timestamp of the rotation batch whose confirmation is withdrawn
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the action was not tracked
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly int $batchTimestamp,
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly ?string $requestId,
    ) {
    }

    /**
     * Builds the frame from the validated action payload and the dispatch that carried it.
     *
     * @param LogsTakeoutUndoActionDTO $dto Validated withdrawal request
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the action was not tracked
     * @return self Frame addressed to the node named in the request
     */
    public static function fromAction(
        LogsTakeoutUndoActionDTO $dto,
        string $acceptKey,
        string $action,
        ?string $requestId,
    ): self {
        return new self(
            nodeId: $dto->nodeId,
            batchTimestamp: $dto->batchTimestamp,
            acceptKey: $acceptKey,
            action: $action,
            requestId: $requestId,
        );
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            LogsTakeoutUndoActionDTO::nodeId => $this->nodeId,
            LogsTakeoutUndoActionDTO::batchTimestamp => $this->batchTimestamp,
            self::acceptKey => $this->acceptKey,
            self::action => $this->action,
            self::requestId => $this->requestId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the owner cannot act without is absent or
     *     holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, LogsTakeoutUndoActionDTO::nodeId),
            batchTimestamp: self::requireInt($data, LogsTakeoutUndoActionDTO::batchTimestamp),
            acceptKey: self::requireString($data, self::acceptKey),
            action: self::requireString($data, self::action),
            requestId: self::optionalString($data, self::requestId),
        );
    }
}
