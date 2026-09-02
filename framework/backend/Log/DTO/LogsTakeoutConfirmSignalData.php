<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;

/**
 * Rotations page → {@see LogStoreAgent} payload for the logs_agent_takeout_confirm signal (HIL-483).
 *
 * The confirmation as it travels to the node that owns the archive: the batch the browser named,
 * plus the three fields that let the answer find its way back. The page does not answer this
 * action itself ({@see AbstractHilosLogsRotationsPage::onAction()} defers it), so the owner is the
 * last step and needs the accept key, the action name and the request id the browser minted.
 *
 * {@see $nodeId} is also the address: the owner declares it under
 * {@see AgentSignalConfigKey::NODE_FIELD}, and the router turns a foreign id into a delivery over
 * the peer channel. An empty id is this node, which is what a single-node install always sends.
 *
 * {@see $userId} rides along because the marker records who confirmed, and the node cannot ask:
 * the browser is attached to the page worker, not to the owner of the directory. It is the one
 * field here the browser does not send — the page fills it from the session it already holds.
 */
final class LogsTakeoutConfirmSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: accept key of the connection waiting for the confirmation. */
    public const string acceptKey = 'acceptKey';

    /** Payload key: name of the action the reply acknowledges. */
    public const string action = 'action';

    /** Payload key: request id the browser minted, quoted back on the ack, absent when untracked. */
    public const string requestId = 'requestId';

    /** Payload key: id of the user who confirmed, absent when the connection carries no user. */
    public const string userId = 'userId';

    /**
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @param int $batchTimestamp Unix timestamp of the rotation batch being confirmed
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the action was not tracked
     * @param ?int $userId Id of the user who confirmed, or null when the connection carries no user
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly int $batchTimestamp,
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly ?string $requestId,
        public readonly ?int $userId,
    ) {
    }

    /**
     * Builds the frame from the validated action payload and the dispatch that carried it.
     *
     * @param LogsTakeoutConfirmActionDTO $dto Validated confirmation request
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the action was not tracked
     * @param ?int $userId Id of the user who confirmed, or null when the connection carries no user
     * @return self Frame addressed to the node named in the request
     */
    public static function fromAction(
        LogsTakeoutConfirmActionDTO $dto,
        string $acceptKey,
        string $action,
        ?string $requestId,
        ?int $userId,
    ): self {
        return new self(
            nodeId: $dto->nodeId,
            batchTimestamp: $dto->batchTimestamp,
            acceptKey: $acceptKey,
            action: $action,
            requestId: $requestId,
            userId: $userId,
        );
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            LogsTakeoutConfirmActionDTO::nodeId => $this->nodeId,
            LogsTakeoutConfirmActionDTO::batchTimestamp => $this->batchTimestamp,
            self::acceptKey => $this->acceptKey,
            self::action => $this->action,
            self::requestId => $this->requestId,
            self::userId => $this->userId,
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
            nodeId: self::requireString($data, LogsTakeoutConfirmActionDTO::nodeId),
            batchTimestamp: self::requireInt($data, LogsTakeoutConfirmActionDTO::batchTimestamp),
            acceptKey: self::requireString($data, self::acceptKey),
            action: self::requireString($data, self::action),
            requestId: self::optionalString($data, self::requestId),
            userId: self::optionalInt($data, self::userId),
        );
    }
}
