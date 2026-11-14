<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Hilos\BaseDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;

/**
 * One frame of an ordinary RT stream, the same on the worker link and on the peer link: a part of
 * an operation, or the receiver's answer to one.
 *
 * A part carries at most {@see self::MAX_PART_BYTES} decoded bytes of its operation's logical
 * object; the bytes travel as strict base64, so this frame is the one bounded thing a transport
 * json-encodes. An answer carries no bytes, only its status. The transport adds its own type key
 * around these fields ({@see WorkerRtSyncStreamDTO},
 * {@see PeerRtSyncStreamDTO}).
 */
final class RtSyncStreamFrame extends BaseDTO
{
    /** @var int Random bytes a stream id is drawn from; its hex is twice as long */
    public const int STREAM_ID_BYTES = 16;
    /** @var int Most decoded payload bytes one part carries */
    public const int MAX_PART_BYTES = 16384;

    /** @var string Payload key: part or answer */
    public const string FIELD_KIND = 'kind';
    /** @var string Payload key: the stream of one direction of one channel instance */
    public const string FIELD_STREAM_ID = 'streamId';
    /** @var string Payload key: the operation's position in its stream, from one */
    public const string FIELD_SEQUENCE = 'sequence';
    /** @var string Payload key: the part's position in its operation, from zero */
    public const string FIELD_PART = 'part';
    /** @var string Payload key: whether the part is its operation's last */
    public const string FIELD_FINAL = 'final';
    /** @var string Payload key: the part's bytes, strict base64 */
    public const string FIELD_DATA = 'data';
    /** @var string Payload key: the answer's status */
    public const string FIELD_STATUS = 'status';

    /** @var string Kind of a frame carrying a part of an operation */
    public const string KIND_PART = 'part';
    /** @var string Kind of a frame answering a part */
    public const string KIND_ACK = 'ack';

    /** @var string Shape of a stream id: 32 lowercase hex digits */
    private const string STREAM_ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * @param string $kind {@see self::KIND_PART} or {@see self::KIND_ACK}
     * @param string $streamId Stream of one direction of one channel instance
     * @param int $sequence Operation's position in its stream, from one
     * @param int $part Part's position in its operation, from zero
     * @param bool $final Whether a part is its operation's last; false on an answer
     * @param string $data Decoded bytes of a part; empty on an answer
     * @param ?RtSyncStreamStatus $status Status of an answer; null on a part
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $streamId,
        public readonly int $sequence,
        public readonly int $part,
        public readonly bool $final,
        public readonly string $data,
        public readonly ?RtSyncStreamStatus $status,
    ) {
    }

    /**
     * @param string $streamId Stream of one direction of one channel instance
     * @param int $sequence Operation's position in its stream, from one
     * @param int $part Part's position in its operation, from zero
     * @param bool $final Whether the part is its operation's last
     * @param string $data Decoded bytes, at most {@see self::MAX_PART_BYTES}
     * @return self Part frame
     * @throws InvalidFormatException When a field is outside its domain
     */
    public static function part(string $streamId, int $sequence, int $part, bool $final, string $data): self
    {
        $frame = new self(self::KIND_PART, $streamId, $sequence, $part, $final, $data, null);
        $frame->assertDomain();

        return $frame;
    }

    /**
     * @param string $streamId Stream the answered part belongs to
     * @param int $sequence Answered operation
     * @param int $part Answered part
     * @param RtSyncStreamStatus $status Answer
     * @return self Answer frame
     * @throws InvalidFormatException When a field is outside its domain
     */
    public static function ack(string $streamId, int $sequence, int $part, RtSyncStreamStatus $status): self
    {
        $frame = new self(self::KIND_ACK, $streamId, $sequence, $part, false, '', $status);
        $frame->assertDomain();

        return $frame;
    }

    /** @return bool Whether this frame carries a part rather than an answer */
    public function isPart(): bool
    {
        return $this->kind === self::KIND_PART;
    }

    /**
     * @return array<string, mixed> Wire fields, without the transport's type key
     */
    public function toArray(): array
    {
        $fields = [
            self::FIELD_KIND => $this->kind,
            self::FIELD_STREAM_ID => $this->streamId,
            self::FIELD_SEQUENCE => $this->sequence,
            self::FIELD_PART => $this->part,
        ];
        if ($this->status === null) {
            $fields[self::FIELD_FINAL] = $this->final;
            $fields[self::FIELD_DATA] = base64_encode($this->data);
        } else {
            $fields[self::FIELD_STATUS] = $this->status->value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data Wire fields
     * @return static Part or answer
     * @throws InvalidFormatException When a field is missing, of the wrong type or outside its domain,
     *     or the bytes are not strict base64
     */
    public static function fromArray(array $data): static
    {
        $kind = self::requireString($data, self::FIELD_KIND);
        $streamId = self::requireString($data, self::FIELD_STREAM_ID);
        $sequence = self::requireInt($data, self::FIELD_SEQUENCE);
        $part = self::requireInt($data, self::FIELD_PART);
        if ($kind === self::KIND_ACK) {
            $status = RtSyncStreamStatus::tryFrom(self::requireString($data, self::FIELD_STATUS))
                ?? throw new InvalidFormatException('An RT stream answer carries an unknown status');

            return self::ack($streamId, $sequence, $part, $status);
        }
        if ($kind !== self::KIND_PART) {
            throw new InvalidFormatException('An RT stream frame is neither a part nor an answer');
        }
        $encoded = self::requireString($data, self::FIELD_DATA);
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded) {
            throw new InvalidFormatException('An RT stream part carries bytes that are not strict base64');
        }

        return self::part($streamId, $sequence, $part, self::requireBool($data, self::FIELD_FINAL), $bytes);
    }

    /**
     * @throws InvalidFormatException When a field is outside its domain
     */
    private function assertDomain(): void
    {
        if (preg_match(self::STREAM_ID_PATTERN, $this->streamId) !== 1) {
            throw new InvalidFormatException('An RT stream id is 32 lowercase hex digits');
        }
        if ($this->sequence < 1 || $this->part < 0) {
            throw new InvalidFormatException('An RT stream position starts at sequence one and part zero');
        }
        if (strlen($this->data) > self::MAX_PART_BYTES) {
            throw new InvalidFormatException('An RT stream part carries more bytes than one part may');
        }
    }
}
