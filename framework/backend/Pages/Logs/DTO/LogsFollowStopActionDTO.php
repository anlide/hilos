<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * DTO for the logs_follow_stop action payload: the node the viewer believes it is following.
 *
 * The id travels because the browser knows which node it asked to follow and saying so costs a
 * string, but the page does not take the removal from it: it addresses the owner it recorded when
 * the follow began ({@see AbstractHilosLogsViewPage::onUnsubscribe()} has no payload to read at
 * all and must work the same way). Trusting the payload here would let a stale switch send the
 * removal to a node that is not holding the follow, and leave the one that is reading forever.
 */
final class LogsFollowStopActionDTO extends ActionPayloadDTO
{
    /** Payload key: id of the node the viewer believes it is following, empty for this node. */
    public const string nodeId = 'nodeId';

    /**
     * @param string $nodeId Id of the node the viewer believes it is following, empty for this node
     */
    public function __construct(
        public readonly string $nodeId,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::LOGS_FOLLOW_STOP;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the node id is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(nodeId: self::requireString($inner, self::nodeId));
    }

    /**
     * @return array<string, mixed> Data naming the node the viewer believes it is following
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
        ];
    }
}
