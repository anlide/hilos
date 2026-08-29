<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;

/**
 * Viewer page → {@see LogStoreAgent} payload for the logs_agent_follow_start signal.
 *
 * The four fields the browser asked with, plus the three that let the answer find its way back:
 * the page defers its own ack ({@see AbstractHilosLogsViewPage::onAction()}), so the owner is the
 * last step of this action and answers the browser itself, over a socket another node may hold.
 *
 * {@see $requestId} does double duty and is required for both: it correlates the ack, and it is
 * the id of the follow itself - every appended frame is stamped with it, so a viewer that has
 * since switched stream or level can drop the frames of the follow it left behind.
 *
 * {@see $nodeId} is also the address: the owner declares it under
 * {@see AgentSignalConfigKey::NODE_FIELD}, and the router turns a foreign id into a delivery over
 * the peer channel. An empty id is this node, which is what a single-node install always sends.
 */
final class LogsFollowStartSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: accept key of the connection that will receive the appended lines. */
    public const string acceptKey = 'acceptKey';

    /** Payload key: name of the action the reply acknowledges. */
    public const string action = 'action';

    /** Payload key: request id the browser minted, quoted back on the ack and on every frame. */
    public const string requestId = 'requestId';

    /**
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @param string $stream File name of the live stream to follow
     * @param ?string $level Level filter, or null for any level
     * @param ?string $substring Substring filter, or null for no substring filter
     * @param string $acceptKey Accept key of the connection that will receive the appended lines
     * @param string $action Action name the reply acknowledges
     * @param string $requestId Request id the browser minted, and the id of this follow
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $stream,
        public readonly ?string $level,
        public readonly ?string $substring,
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly string $requestId,
    ) {
    }

    /**
     * Builds the frame from the validated action payload and the dispatch that carried it.
     *
     * @param LogsFollowStartActionDTO $dto Validated follow request
     * @param string $acceptKey Accept key of the connection that will receive the appended lines
     * @param string $action Action name the reply acknowledges
     * @param string $requestId Request id the browser minted, and the id of this follow
     * @return self Frame addressed to the node named in the request
     */
    public static function fromAction(
        LogsFollowStartActionDTO $dto,
        string $acceptKey,
        string $action,
        string $requestId,
    ): self {
        return new self(
            nodeId: $dto->nodeId,
            stream: $dto->stream,
            level: $dto->level,
            substring: $dto->substring,
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
            LogsFollowStartActionDTO::nodeId => $this->nodeId,
            LogsFollowStartActionDTO::stream => $this->stream,
            LogsFollowStartActionDTO::level => $this->level,
            LogsFollowStartActionDTO::substring => $this->substring,
            self::acceptKey => $this->acceptKey,
            self::action => $this->action,
            self::requestId => $this->requestId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the owner cannot follow without is absent or
     *     holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, LogsFollowStartActionDTO::nodeId),
            stream: self::requireString($data, LogsFollowStartActionDTO::stream),
            level: self::optionalString($data, LogsFollowStartActionDTO::level),
            substring: self::optionalString($data, LogsFollowStartActionDTO::substring),
            acceptKey: self::requireString($data, self::acceptKey),
            action: self::requireString($data, self::action),
            requestId: self::requireString($data, self::requestId),
        );
    }
}
