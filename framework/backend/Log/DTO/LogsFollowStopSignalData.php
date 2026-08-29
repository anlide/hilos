<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;

/**
 * Viewer page → {@see LogStoreAgent} payload for the logs_agent_follow_stop signal.
 *
 * Two fields, because removal needs no request: the owner keys its followers by accept key, and
 * {@see $nodeId} is the address the page recorded when the follow began - not the one the browser
 * sent with the removal, which may name the node it has already switched away from.
 *
 * Nothing comes back. Removal cannot fail in a way the viewer could act on, and the page answers
 * it synchronously rather than holding the browser in loading for a fact about somebody else
 * (HIL-389). {@see AgentSignalConfigKey::NODE_FIELD} routes it the same way the start is routed.
 */
final class LogsFollowStopSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: accept key of the connection whose follow is being removed. */
    public const string acceptKey = 'acceptKey';

    /**
     * @param string $nodeId Id of the node holding the follow, empty for this node
     * @param string $acceptKey Accept key of the connection whose follow is being removed
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $acceptKey,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            LogsFollowStopActionDTO::nodeId => $this->nodeId,
            self::acceptKey => $this->acceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the owner cannot act without is absent or holds
     *     a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, LogsFollowStopActionDTO::nodeId),
            acceptKey: self::requireString($data, self::acceptKey),
        );
    }
}
