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
 * DTO for the logs_read_lines action payload: which file, on which node, and which slice of it.
 *
 * The file is named STRUCTURALLY - a source, a batch stamp and a stream name - and never as a
 * path: the backend assembles the path under the log root, so a browser cannot name a place in
 * the file system at all. The reader's traversal guard stays as the second line, not the first.
 *
 * Read direction is not carried. Both the first page and the Earlier button read backwards from
 * the tail, and a field nobody sets would be filled in by guesswork the day someone needs the
 * other direction (HIL-389 will declare its own when it does).
 */
final class LogsReadLinesActionDTO extends ActionPayloadDTO
{
    /** Source value: the live log file at the root of the log directory. */
    public const string SOURCE_LIVE = 'live';

    /** Source value: a file inside one rotated batch under the archive subdirectory. */
    public const string SOURCE_BATCH = 'batch';

    /** Payload key: id of the node owning the file, empty for this node. */
    public const string nodeId = 'nodeId';

    /** Payload key: which half of the store to read, {@see SOURCE_LIVE} or {@see SOURCE_BATCH}. */
    public const string source = 'source';

    /** Payload key: unix timestamp of the rotated batch, required when the source is a batch. */
    public const string batchTimestamp = 'batchTimestamp';

    /** Payload key: file name of the stream inside the source, for example `worker-0.log`. */
    public const string stream = 'stream';

    /** Payload key: keep only lines of this level, a {@see Logger} `LEVEL_*` value. */
    public const string level = 'level';

    /** Payload key: keep only lines containing this text. */
    public const string substring = 'substring';

    /** Payload key: byte offset the previous page ended at, absent on the first page. */
    public const string cursor = 'cursor';

    /**
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @param string $source Which half of the store to read
     * @param ?int $batchTimestamp Unix timestamp of the rotated batch, or null for the live source
     * @param string $stream File name of the stream inside the source
     * @param ?string $level Level filter, or null for any level
     * @param ?string $substring Substring filter, or null for no substring filter
     * @param ?int $cursor Byte offset to continue from, or null for the first page
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $source,
        public readonly ?int $batchTimestamp,
        public readonly string $stream,
        public readonly ?string $level,
        public readonly ?string $substring,
        public readonly ?int $cursor,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::LOGS_READ_LINES;
    }

    /**
     * Reads and validates one read request.
     *
     * Everything checked here is checked because the owner on the other node cannot check it
     * for the browser: it answers on a socket it does not hold, so a request that makes no
     * sense must fail here, correlated, rather than travel and come back as an empty page.
     *
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the source, the stream, the batch stamp, the level or
     *     the cursor is absent where required, or holds a value the reader cannot act on
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $source = self::requireString($inner, self::source);
        if ($source !== self::SOURCE_LIVE && $source !== self::SOURCE_BATCH) {
            throw new InvalidFormatException("Unknown log source: {$source}");
        }

        $batchTimestamp = self::optionalInt($inner, self::batchTimestamp);
        if ($source === self::SOURCE_BATCH && $batchTimestamp === null) {
            throw new InvalidFormatException('A batch source names the batch it reads');
        }
        if ($batchTimestamp !== null && $batchTimestamp < 0) {
            throw new InvalidFormatException('Batch timestamp precedes the epoch');
        }

        $stream = trim(self::requireString($inner, self::stream));
        if ($stream === '') {
            throw new InvalidFormatException('A read names the stream it reads');
        }

        $level = self::optionalString($inner, self::level);
        if ($level !== null && !LogLineReader::isKnownLevel($level)) {
            throw new InvalidFormatException("Unknown log level: {$level}");
        }

        $cursor = self::optionalInt($inner, self::cursor);
        if ($cursor !== null && $cursor < 0) {
            throw new InvalidFormatException('Cursor precedes the start of the file');
        }

        return new static(
            nodeId: self::requireString($inner, self::nodeId),
            source: $source,
            // The live source has no batch to name, and a stamp sent beside it would be a second
            // opinion about which file this is. It is dropped, not carried and ignored.
            batchTimestamp: $source === self::SOURCE_BATCH ? $batchTimestamp : null,
            stream: $stream,
            level: $level,
            substring: self::optionalString($inner, self::substring),
            cursor: $cursor,
        );
    }

    /**
     * @return array<string, mixed> Data naming the file and the slice of it to read
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::source => $this->source,
            self::batchTimestamp => $this->batchTimestamp,
            self::stream => $this->stream,
            self::level => $this->level,
            self::substring => $this->substring,
            self::cursor => $this->cursor,
        ];
    }
}
