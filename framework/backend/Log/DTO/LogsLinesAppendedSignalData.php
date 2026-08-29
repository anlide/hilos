<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogLinePage;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;

/**
 * {@see LogStoreAgent} → the following viewer: what happened to the file since the last frame.
 *
 * One of four things, and never two at once, which is why the frame has four shapes and four
 * named constructors: lines were appended ({@see appended()}), the file was carried off or
 * truncated and the follow restarted at the beginning of the new one ({@see rotated()}), the
 * viewer had fallen so far behind that the owner jumped to the end ({@see skipped()}), or the
 * follow ended on the owner's side ({@see stopped()}). A tick with nothing to say sends no frame
 * at all - silence under a level filter is the right answer, not a fault.
 *
 * {@see $followId} is the request id of the start, so a viewer that has since switched stream,
 * level or node recognizes and drops the frames of the follow it left behind - they may still be
 * in flight when the new one begins.
 *
 * A line is a flat array of the same three keys the read reply uses
 * ({@see LogsReadLinesReplyDTO::linesFromPage()}): the browser draws the first page and every
 * frame after it with one renderer, so a second shape here would be a second renderer.
 */
final class LogsLinesAppendedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: request id of the logs_follow_start this frame belongs to. */
    public const string followId = 'followId';

    /** Payload key: the appended lines, oldest first. */
    public const string lines = 'lines';

    /** Payload key: whether the file was replaced under the follow and reading restarted at its start. */
    public const string rotated = 'rotated';

    /** Payload key: bytes the owner jumped over to catch up, absent when it did not jump. */
    public const string skippedBytes = 'skippedBytes';

    /** Payload key: whether the follow has ended on the owner's side. */
    public const string stopped = 'stopped';

    /**
     * @param string $followId Request id of the start this frame belongs to
     * @param list<array{text: string, level: string, isContinuation: bool}> $lines Appended lines, oldest first
     * @param bool $rotated Whether the file was replaced and reading restarted at the start of the new one
     * @param ?int $skippedBytes Bytes jumped over to catch up, or null when nothing was skipped
     * @param bool $stopped Whether the follow has ended on the owner's side
     */
    public function __construct(
        public readonly string $followId,
        public readonly array $lines,
        public readonly bool $rotated,
        public readonly ?int $skippedBytes,
        public readonly bool $stopped,
    ) {
    }

    /**
     * Frame carrying the lines one read appended.
     *
     * @param string $followId Request id of the start this frame belongs to
     * @param LogLinePage $page Page the forward read produced
     * @return self Frame carrying those lines and nothing else
     */
    public static function appended(string $followId, LogLinePage $page): self
    {
        return new self($followId, LogsReadLinesReplyDTO::linesFromPage($page), false, null, false);
    }

    /**
     * Frame saying the file was carried off or truncated, and the follow restarted on the new one.
     *
     * @param string $followId Request id of the start this frame belongs to
     * @return self Frame carrying the rotation and no lines
     */
    public static function rotated(string $followId): self
    {
        return new self($followId, [], true, null, false);
    }

    /**
     * Frame saying the owner jumped to the end of the file rather than shipping the backlog.
     *
     * @param string $followId Request id of the start this frame belongs to
     * @param int $skippedBytes Bytes jumped over
     * @return self Frame carrying the size of the gap and no lines
     */
    public static function skipped(string $followId, int $skippedBytes): self
    {
        return new self($followId, [], false, $skippedBytes, false);
    }

    /**
     * Frame saying the follow ended on the owner's side, so the viewer stops claiming it is live.
     *
     * @param string $followId Request id of the start this frame belongs to
     * @return self Frame carrying the end of the follow
     */
    public static function stopped(string $followId): self
    {
        return new self($followId, [], false, null, true);
    }

    /**
     * @return array<string, mixed> Frame as it goes out to the viewer
     */
    public function toArray(): array
    {
        return [
            self::followId => $this->followId,
            self::lines => $this->lines,
            self::rotated => $this->rotated,
            self::skippedBytes => $this->skippedBytes,
            self::stopped => $this->stopped,
        ];
    }

    /**
     * Reads a frame back from its wire form.
     *
     * Present for the base contract: the frame travels one way, from the owner to the browser, so
     * nothing on this side rebuilds one. It refuses a line missing its text, its level or its
     * continuation flag rather than filling it in, for the reason the read reply does - a frame
     * that repaired itself here would disagree with the file.
     *
     * @param array<string, mixed> $data Wire form of a frame
     * @return static Restored frame
     * @throws InvalidFormatException When a field the frame has no meaning without is absent or
     *     holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $lines = [];
        foreach (self::requireArray($data, self::lines) as $line) {
            if (!is_array($line)) {
                throw new InvalidFormatException('Payload carries a non-array line under key ' . self::lines);
            }

            $lines[] = [
                LogsReadLinesReplyDTO::text => self::requireString($line, LogsReadLinesReplyDTO::text),
                LogsReadLinesReplyDTO::level => self::requireString($line, LogsReadLinesReplyDTO::level),
                LogsReadLinesReplyDTO::isContinuation => self::requireBool($line, LogsReadLinesReplyDTO::isContinuation),
            ];
        }

        return new static(
            followId: self::requireString($data, self::followId),
            lines: $lines,
            rotated: self::requireBool($data, self::rotated),
            skippedBytes: self::optionalInt($data, self::skippedBytes),
            stopped: self::requireBool($data, self::stopped),
        );
    }
}
