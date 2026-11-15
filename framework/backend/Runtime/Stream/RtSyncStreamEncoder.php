<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use LogicException;

/**
 * Writes one operation's logical object as consecutive bytes, a part at a time, without ever
 * holding the object or one of its values whole: each value is read from its segmented pages as
 * the budget allows, and only the bytes of the part being cut wait in the buffer.
 *
 * A map is always written as a JSON object, a list included: its keys are written as their
 * text, and the reader takes a canonical integer key back as an integer, as a native array would.
 * Text is written byte for byte with JSON's escapes, so the bytes a row holds are the bytes the
 * receiver gets.
 *
 * A snapshot ({@see RtSyncStreamSnapshot}) writes its rows as its cut reads them out, on the
 * allowance of the part being cut: neither the cut nor the object is held whole, only the rows of
 * one read and the bytes of the part.
 */
final class RtSyncStreamEncoder
{
    /** @var array<string, string> JSON escapes of the bytes a string may not carry raw */
    private const array ESCAPES = [
        '"' => '\\"',
        '\\' => '\\\\',
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
    ];
    /** @var string Bytes that need an escape inside a JSON string */
    private const string ESCAPED_BYTES = '/["\\\\\x00-\x1f]/';

    /** @var ?Generator<int, RtWork, int, void> The object's bytes, appended to the buffer as granted */
    private ?Generator $bytes = null;
    private string $buffer = '';
    /** Allowance of the part being cut, which a snapshot's cut is read out on */
    private ?RtWorkBudget $budget = null;

    /**
     * @param RtSyncStreamOperation|RtSyncStreamSnapshot $operation Operation to write
     */
    public function __construct(private readonly RtSyncStreamOperation|RtSyncStreamSnapshot $operation)
    {
    }

    /**
     * Cuts the next part, once the budget has let enough of the object be written for it.
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return ?string Bytes of the next part, at most {@see RtSyncStreamFrame::MAX_PART_BYTES},
     *     or null when the budget ran out before the part was whole
     * @throws InvalidFormatException When a value cannot be written as JSON, or a snapshot's cut holds a malformed identity
     * @throws LogicException When a segmented lease already closed or the work protocol is broken
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    public function nextPart(RtWorkBudget $budget): ?string
    {
        $this->budget = $budget;
        $this->bytes ??= $this->operation instanceof RtSyncStreamSnapshot ? $this->snapshot($this->operation) : $this->object($this->operation);
        while (strlen($this->buffer) < RtSyncStreamFrame::MAX_PART_BYTES && $this->bytes->valid()) {
            $request = $this->bytes->current();
            if (!$request instanceof RtWork) {
                throw new LogicException('An RT stream encoder yields RtWork requests only');
            }
            $granted = $budget->take($request->bytes);
            if ($granted === null) {
                return null;
            }
            $this->bytes->send($granted);
        }
        $part = substr($this->buffer, 0, RtSyncStreamFrame::MAX_PART_BYTES);
        $this->buffer = substr($this->buffer, RtSyncStreamFrame::MAX_PART_BYTES);

        return $part;
    }

    /** @return bool Whether the last part has been cut */
    public function isFinished(): bool
    {
        return $this->buffer === '' && $this->bytes !== null && !$this->bytes->valid();
    }

    /**
     * @param RtSyncStreamOperation $operation Operation to write
     * @return Generator<int, RtWork, int, void> The object, in the sender's field order
     * @throws InvalidFormatException When a value cannot be written as JSON
     * @throws LogicException When a segmented lease already closed
     */
    private function object(RtSyncStreamOperation $operation): Generator
    {
        $header = [
            RtSyncStreamOperation::FIELD_SIGNAL_TYPE => $operation->signalType,
            RtSyncStreamOperation::FIELD_REVISION => $operation->revision,
            RtSyncStreamOperation::FIELD_COLLECTION_KEY => $operation->collectionKey,
            RtSyncStreamOperation::FIELD_STATE_ID => $operation->stateId,
            RtSyncStreamOperation::FIELD_ORIGIN => $operation->origin,
            RtSyncStreamOperation::FIELD_ORIGIN_REQUEST_ID => $operation->originRequestId,
            RtSyncStreamOperation::FIELD_EMITTER => $operation->emitter,
            RtSyncStreamOperation::FIELD_ORIGIN_NODE_ID => $operation->originNodeId,
            RtSyncStreamOperation::FIELD_PARTIAL_OWNER => $operation->partialOwner,
        ];
        $separator = '{';
        foreach ($header as $field => $value) {
            yield new RtWork();
            $this->buffer .= $separator . self::quoted($field) . ':' . (is_string($value) ? self::quoted($value) : self::scalar($value));
            $separator = ',';
        }
        $this->buffer .= ',' . self::quoted(RtSyncStreamOperation::FIELD_ROW) . ':';
        yield from $this->value($operation->row->reader());
        if ($operation->previous !== null) {
            $this->buffer .= ',' . self::quoted(RtSyncStreamOperation::FIELD_PREVIOUS) . ':';
            yield from $this->value($operation->previous->reader());
        }
        yield new RtWork();
        $this->buffer .= '}';
    }

    /**
     * @param RtSyncStreamSnapshot $snapshot Snapshot to write
     * @return Generator<int, RtWork, int, void> The object: its head in the sender's field order, the rows of its
     *     cut as they are read out, then the stale marks
     * @throws InvalidFormatException When a value cannot be written as JSON, or the cut holds a malformed identity
     * @throws LogicException When a segmented lease or the cut's pages closed
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    private function snapshot(RtSyncStreamSnapshot $snapshot): Generator
    {
        $head = [
            RtSyncStreamOperation::FIELD_SIGNAL_TYPE => RtSyncStreamSnapshot::SIGNAL_TYPE,
            RtSyncStreamOperation::FIELD_REVISION => $snapshot->revision,
            RtSyncStreamOperation::FIELD_COLLECTION_KEY => $snapshot->collectionKey,
            RtSyncStreamSnapshot::FIELD_CUT_REVISION => $snapshot->cutRevision,
        ];
        $separator = '{';
        foreach ($head as $field => $value) {
            yield new RtWork();
            $this->buffer .= $separator . self::quoted($field) . ':' . (is_string($value) ? self::quoted($value) : self::scalar($value));
            $separator = ',';
        }
        $this->buffer .= ',' . self::quoted(RtSyncStreamSnapshot::FIELD_SCOPE) . ':{' . self::quoted(RtSyncStreamSnapshot::FIELD_WIDTH)
            . ':' . self::quoted($snapshot->width()) . ',' . self::quoted(RtSyncStreamSnapshot::FIELD_KEYS) . ':';
        $separator = '{';
        foreach ($snapshot->scopeKeys() as $position => $key) {
            yield new RtWork();
            $this->buffer .= $separator . '"' . $position . '":' . self::quoted($key);
            $separator = ',';
        }
        $this->buffer .= ($separator === '{' ? '{}' : '}') . '}';
        $origin = [
            RtSyncStreamOperation::FIELD_ORIGIN_NODE_ID => $snapshot->originNodeId,
            RtSyncStreamOperation::FIELD_PARTIAL_OWNER => $snapshot->partialOwner,
            RtSyncStreamSnapshot::FIELD_ANNOUNCEMENT => $snapshot->announcement,
        ];
        foreach ($origin as $field => $value) {
            yield new RtWork();
            $this->buffer .= ',' . self::quoted($field) . ':' . (is_string($value) ? self::quoted($value) : self::scalar($value));
        }
        $this->buffer .= ',' . self::quoted(RtSyncStreamSnapshot::FIELD_ROWS) . ':';
        $separator = '{';
        while (!$snapshot->isReadOut()) {
            $rows = $snapshot->nextRows($this->budget);
            if ($rows === null) {
                // The read stopped on the part's allowance: wait for the next one.
                yield new RtWork(1);
                continue;
            }
            foreach ($rows->ids as $position => $id) {
                yield new RtWork();
                $this->buffer .= $separator . self::quoted($id) . ':';
                $separator = ',';
                yield from $this->value($rows->values[$position]->reader());
            }
        }
        $this->buffer .= ($separator === '{' ? '{}' : '}') . ',' . self::quoted(RtSyncStreamSnapshot::FIELD_STALE_ROWS) . ':';
        $separator = '{';
        foreach ($snapshot->staleRows() as $id => $since) {
            yield new RtWork();
            $this->buffer .= $separator . self::quoted((string)$id) . ':' . self::scalar($since);
            $separator = ',';
        }
        yield new RtWork();
        $this->buffer .= ($separator === '{' ? '{}' : '}') . '}';
    }

    /**
     * @param RtRowReader $reader Segmented value
     * @return Generator<int, RtWork, int, void> The value as JSON, a granted piece at a time
     * @throws InvalidFormatException When a value cannot be written as JSON
     * @throws LogicException When a segmented lease already closed
     */
    private function value(RtRowReader $reader): Generator
    {
        if ($reader->kind() === RtRowKind::Scalar) {
            $this->buffer .= self::scalar((yield from $reader->scalar()));

            return;
        }
        if ($reader->kind() === RtRowKind::Text) {
            yield from $this->text($reader);

            return;
        }
        $cursor = $reader->entries();
        $separator = '{';
        while (($entry = yield from $cursor->advance()) !== null) {
            $this->buffer .= $separator;
            $separator = ',';
            $key = $entry->key->reader();
            if ($key->kind() === RtRowKind::Scalar) {
                $this->buffer .= '"' . self::scalar((yield from $key->scalar())) . '"';
            } else {
                yield from $this->text($key);
            }
            $this->buffer .= ':';
            yield from $this->value($entry->value->reader());
        }
        $this->buffer .= $separator === '{' ? '{}' : '}';
    }

    /**
     * @param RtRowReader $reader Segmented text
     * @return Generator<int, RtWork, int, void> The quoted text, a granted piece at a time
     * @throws InvalidFormatException When the value is not text
     */
    private function text(RtRowReader $reader): Generator
    {
        $cursor = $reader->text();
        $this->buffer .= '"';
        while (($part = yield from $cursor->advance()) !== null) {
            $this->buffer .= self::escaped($part);
        }
        $this->buffer .= '"';
    }

    /**
     * @param string $text Bounded header text
     * @return string The text as a JSON string
     */
    private static function quoted(string $text): string
    {
        return '"' . self::escaped($text) . '"';
    }

    /**
     * @param string $bytes Raw bytes of a piece of text
     * @return string The bytes with JSON's escapes, any other byte left as it is
     */
    private static function escaped(string $bytes): string
    {
        return preg_replace_callback(
            self::ESCAPED_BYTES,
            static fn(array $match): string => self::ESCAPES[$match[0]] ?? sprintf('\\u%04x', ord($match[0])),
            $bytes,
        );
    }

    /**
     * @param null|bool|int|float $value Fixed-size scalar
     * @return string The scalar as a JSON literal; a float always keeps a fraction or an exponent
     * @throws InvalidFormatException When a float is not finite
     */
    private static function scalar(null|bool|int|float $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidFormatException('A runtime float that is not finite has no JSON form');
            }
            $text = var_export($value, true);

            return strpbrk($text, '.eE') === false ? $text . '.0' : $text;
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string)$value,
        };
    }
}
