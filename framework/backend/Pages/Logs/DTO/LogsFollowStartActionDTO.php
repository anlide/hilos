<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Log\LogLineReader;
use Hilos\Utils\Logger;

/**
 * DTO for the logs_follow_start action payload: which live file to follow, and through which filters.
 *
 * A shorter request than {@see LogsReadLinesActionDTO} by three fields, and each absence is a
 * statement. There is no source and no batch stamp because a rotated batch has no tail - nobody
 * writes into it any more, which is why the mockup greys the switch out for one. There is no
 * cursor because the starting position is the end of the file at the moment the owner takes it,
 * and only the owner can see that moment (HIL-389).
 */
final class LogsFollowStartActionDTO extends ActionPayloadDTO
{
    /** Payload key: id of the node owning the file, empty for this node. */
    public const string nodeId = 'nodeId';

    /** Payload key: file name of the live stream to follow, for example `worker-0.log`. */
    public const string stream = 'stream';

    /** Payload key: keep only lines of this level, a {@see Logger} `LEVEL_*` value. */
    public const string level = 'level';

    /** Payload key: keep only lines containing this text. */
    public const string substring = 'substring';

    /**
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @param string $stream File name of the live stream to follow
     * @param ?string $level Level filter, or null for any level
     * @param ?string $substring Substring filter, or null for no substring filter
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $stream,
        public readonly ?string $level,
        public readonly ?string $substring,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::LOGS_FOLLOW_START;
    }

    /**
     * Reads and validates one request to start following a file.
     *
     * Refused here for the reason a read is: the owner answers on a socket it does not hold, so a
     * request that names nothing readable must fail while the browser is still correlated to it.
     *
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the stream is absent or empty, or the level is one the
     *     reader would never match
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $stream = trim(self::requireString($inner, self::stream));
        if ($stream === '') {
            throw new InvalidFormatException('A follow names the stream it follows');
        }

        $level = self::optionalString($inner, self::level);
        if ($level !== null && !LogLineReader::isKnownLevel($level)) {
            throw new InvalidFormatException("Unknown log level: {$level}");
        }

        return new static(
            nodeId: self::requireString($inner, self::nodeId),
            stream: $stream,
            level: $level,
            substring: self::optionalString($inner, self::substring),
        );
    }

    /**
     * @return array<string, mixed> Data naming the file to follow and the filters to follow it through
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::stream => $this->stream,
            self::level => $this->level,
            self::substring => $this->substring,
        ];
    }
}
