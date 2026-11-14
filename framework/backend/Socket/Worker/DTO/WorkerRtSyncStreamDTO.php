<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * One frame of the ordinary RT stream on a worker link, in either direction: a part of an
 * operation or the answer to one ({@see RtSyncStreamFrame}).
 */
final class WorkerRtSyncStreamDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SYNC_STREAM;

    /**
     * @param RtSyncStreamFrame $frame Part or answer
     */
    public function __construct(public readonly RtSyncStreamFrame $frame)
    {
    }

    /** @return string Message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Frame fields under the message type */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE] + $this->frame->toArray();
    }

    /**
     * @param array<string, mixed> $data Frame fields under the message type
     * @return static Stream frame message
     * @throws InvalidFormatException When the frame is malformed or outside its domain
     */
    public static function fromArray(array $data): static
    {
        return new static(RtSyncStreamFrame::fromArray($data));
    }
}
