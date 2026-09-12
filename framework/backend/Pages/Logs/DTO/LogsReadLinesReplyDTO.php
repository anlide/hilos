<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\LogLine;
use Hilos\Log\LogLinePage;

/**
 * Reply to logs_read_lines: one page of lines, and where the page before it ends.
 *
 * Unreadable is a STATE here, not a failure: a file the rotation carried off, a directory that
 * cannot be listed and a path the traversal guard refused all come back with {@see $readable}
 * false and no lines, the way {@see LogLinePage::unavailable()} answers the reader. Only what an
 * operator can put right - an unknown node, a node that is down, a payload that names nothing -
 * is an action error.
 *
 * A line is a flat array rather than an object because this reply rides the action ack as it is:
 * the receiver never rebuilds a class from it, so a nested DTO would be typing nobody reads.
 * Nothing derived travels either - no line numbers, no parsed time - because the reader does not
 * count them, and a number computed on this side would disagree with the file.
 *
 * A read opened on an anchor says whether the anchor was found ({@see $anchorFound}, HIL-868).
 * Not finding it is a state as well, and the page is then the ordinary one from the tail: the
 * flag is what lets the screen say this is not the place it asked for, rather than show the tail
 * as if it were.
 */
final class LogsReadLinesReplyDTO extends ActionReplyDTO
{
    /** Reply key: whether the named file could be read at all. */
    public const string readable = 'readable';

    /** Reply key: the matched lines, oldest first. */
    public const string lines = 'lines';

    /** Reply key: byte offset to send back to reach the page before this one. */
    public const string nextCursor = 'nextCursor';

    /** Reply key: whether older matching lines remain beyond this page. */
    public const string hasMore = 'hasMore';

    /** Reply key: whether the anchor the read asked for was found; null when it asked for none (HIL-868). */
    public const string anchorFound = 'anchorFound';

    /** Line key: the line text, without its trailing newline. */
    public const string text = 'text';

    /** Line key: the level the reader recognized, inherited by a continuation. */
    public const string level = 'level';

    /** Line key: whether the line continues the entry above it instead of starting one. */
    public const string isContinuation = 'isContinuation';

    /**
     * @param bool $readable Whether the named file could be read
     * @param list<array{text: string, level: string, isContinuation: bool}> $lines Matched lines, oldest first
     * @param ?int $nextCursor Byte offset of the page before this one, or null when none remains
     * @param bool $hasMore Whether older matching lines remain beyond this page
     * @param ?bool $anchorFound Whether the anchor the read asked for was found, or null when it asked for none
     */
    public function __construct(
        public readonly bool $readable,
        public readonly array $lines,
        public readonly ?int $nextCursor,
        public readonly bool $hasMore,
        public readonly ?bool $anchorFound = null,
    ) {
    }

    /**
     * Builds the reply from the page the reader returned.
     *
     * @param LogLinePage $page Page the reader produced, unavailable one included
     * @return self Reply carrying that page
     */
    public static function fromPage(LogLinePage $page): self
    {
        return new self(
            readable: $page->readable,
            lines: self::linesFromPage($page),
            nextCursor: $page->nextCursor,
            hasMore: $page->hasMore,
        );
    }

    /**
     * Builds the reply from a page read FORWARD from the line an anchor was found on (HIL-868).
     *
     * The cursor is replaced, and the reason is the contract rather than the page: {@see $nextCursor}
     * means "where to read back from for the page before this one", and the page before one that
     * starts on the anchor ends exactly at the anchor. The Earlier button therefore works on it
     * without knowing how the page was fetched, where the forward read's own cursor would point past
     * the page instead of before it.
     *
     * @param LogLinePage $page Page read forward from the anchor, the anchor's own line first
     * @param int $anchorOffset Byte offset of the line the anchor was found on
     * @return self Reply carrying that page, found
     */
    public static function fromAnchoredPage(LogLinePage $page, int $anchorOffset): self
    {
        return new self(
            readable: $page->readable,
            lines: self::linesFromPage($page),
            nextCursor: $anchorOffset > 0 ? $anchorOffset : null,
            hasMore: $anchorOffset > 0,
            anchorFound: true,
        );
    }

    /**
     * Builds the reply from the ordinary page from the tail that stands in for an anchor not found (HIL-868).
     *
     * The rotation carried the file off, the entry lies beyond what the search reads back, or the
     * moment is newer than the file: all three answer alike, with the tail and the flag down.
     *
     * @param LogLinePage $page Page read backwards from the tail, unavailable one included
     * @return self Reply carrying that page, not found
     */
    public static function fromMissedAnchor(LogLinePage $page): self
    {
        return new self(
            readable: $page->readable,
            lines: self::linesFromPage($page),
            nextCursor: $page->nextCursor,
            hasMore: $page->hasMore,
            anchorFound: false,
        );
    }

    /**
     * Flattens the lines of a page into their wire form.
     *
     * Public because the live tail sends the same lines in its own frame
     * ({@see LogsLinesAppendedSignalData}, HIL-389) and the browser draws both with one renderer:
     * a second copy of these three keys would be a second shape for the same thing, free to drift.
     *
     * @param LogLinePage $page Page the reader produced
     * @return list<array{text: string, level: string, isContinuation: bool}> Lines, oldest first
     */
    public static function linesFromPage(LogLinePage $page): array
    {
        return array_map(
            static fn(LogLine $line): array => [
                self::text => $line->text,
                self::level => $line->detectedLevel,
                self::isContinuation => $line->isContinuation,
            ],
            $page->lines,
        );
    }

    /**
     * Reads a reply back from its wire form.
     *
     * Present for the base contract, not for a caller: the ack travels flat and the browser reads
     * it as data, so nothing on this side rebuilds one from the wire. It stays honest all the
     * same - a line missing its text, its level or its continuation flag is refused rather than
     * filled in, because a reply that repaired itself here would disagree with the file.
     *
     * @param array<string, mixed> $data Wire form of a reply
     * @return static Restored reply
     * @throws InvalidFormatException When a field the reply has no meaning without is absent or
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
                self::text => self::requireString($line, self::text),
                self::level => self::requireString($line, self::level),
                self::isContinuation => self::requireBool($line, self::isContinuation),
            ];
        }

        return new static(
            readable: self::requireBool($data, self::readable),
            lines: $lines,
            nextCursor: self::optionalInt($data, self::nextCursor),
            hasMore: self::requireBool($data, self::hasMore),
            anchorFound: self::optionalBool($data, self::anchorFound),
        );
    }

    /**
     * @return array<string, mixed> Reply as it goes out on the action ack
     */
    public function toArray(): array
    {
        return [
            self::readable => $this->readable,
            self::lines => $this->lines,
            self::nextCursor => $this->nextCursor,
            self::hasMore => $this->hasMore,
            self::anchorFound => $this->anchorFound,
        ];
    }
}
