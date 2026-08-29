<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;

/**
 * Viewer page → {@see LogStoreAgent} payload for the logs_agent_read_lines signal.
 *
 * The read request as it travels to the node that owns the file: the seven fields the browser
 * asked with, plus the three that let the answer find its way back. The page does not answer
 * this action itself ({@see AbstractHilosLogsViewPage::onAction()} defers it), so the owner is
 * the last step and needs the accept key, the action name and the request id the browser minted.
 *
 * {@see $nodeId} is also the address: the owner declares it under
 * {@see AgentSignalConfigKey::NODE_FIELD}, and the router turns a foreign id into a delivery over
 * the peer channel. An empty id is this node, which is what a single-node install always sends.
 */
final class LogsReadLinesSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: accept key of the connection waiting for the page of lines. */
    public const string acceptKey = 'acceptKey';

    /** Payload key: name of the action the reply acknowledges. */
    public const string action = 'action';

    /** Payload key: request id the browser minted, quoted back on the ack, absent when untracked. */
    public const string requestId = 'requestId';

    /**
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @param string $source Which half of the store to read
     * @param ?int $batchTimestamp Unix timestamp of the rotated batch, or null for the live source
     * @param string $stream File name of the stream inside the source
     * @param ?string $level Level filter, or null for any level
     * @param ?string $substring Substring filter, or null for no substring filter
     * @param ?int $cursor Byte offset to continue from, or null for the first page
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the read was not tracked
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $source,
        public readonly ?int $batchTimestamp,
        public readonly string $stream,
        public readonly ?string $level,
        public readonly ?string $substring,
        public readonly ?int $cursor,
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly ?string $requestId,
    ) {
    }

    /**
     * Builds the frame from the validated action payload and the dispatch that carried it.
     *
     * @param LogsReadLinesActionDTO $dto Validated read request
     * @param string $acceptKey Accept key of the connection waiting for the answer
     * @param string $action Action name the reply acknowledges
     * @param ?string $requestId Request id the browser minted, or null when the read was not tracked
     * @return self Frame addressed to the node named in the request
     */
    public static function fromAction(
        LogsReadLinesActionDTO $dto,
        string $acceptKey,
        string $action,
        ?string $requestId,
    ): self {
        return new self(
            nodeId: $dto->nodeId,
            source: $dto->source,
            batchTimestamp: $dto->batchTimestamp,
            stream: $dto->stream,
            level: $dto->level,
            substring: $dto->substring,
            cursor: $dto->cursor,
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
            LogsReadLinesActionDTO::nodeId => $this->nodeId,
            LogsReadLinesActionDTO::source => $this->source,
            LogsReadLinesActionDTO::batchTimestamp => $this->batchTimestamp,
            LogsReadLinesActionDTO::stream => $this->stream,
            LogsReadLinesActionDTO::level => $this->level,
            LogsReadLinesActionDTO::substring => $this->substring,
            LogsReadLinesActionDTO::cursor => $this->cursor,
            self::acceptKey => $this->acceptKey,
            self::action => $this->action,
            self::requestId => $this->requestId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the owner cannot read without is absent or
     *     holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, LogsReadLinesActionDTO::nodeId),
            source: self::requireString($data, LogsReadLinesActionDTO::source),
            batchTimestamp: self::optionalInt($data, LogsReadLinesActionDTO::batchTimestamp),
            stream: self::requireString($data, LogsReadLinesActionDTO::stream),
            level: self::optionalString($data, LogsReadLinesActionDTO::level),
            substring: self::optionalString($data, LogsReadLinesActionDTO::substring),
            cursor: self::optionalInt($data, LogsReadLinesActionDTO::cursor),
            acceptKey: self::requireString($data, self::acceptKey),
            action: self::requireString($data, self::action),
            requestId: self::optionalString($data, self::requestId),
        );
    }
}
