<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosSessionRotationRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionRotations;
use LogicException;

/**
 * HilosSessionRotation - one pending token rotation, keyed by its one-time ticket (HIL-582).
 *
 * The row that lets a login rotate the session token without a window an attacker can
 * poll. The seam mints a ticket, writes this row, and sends the ticket to the initiating
 * connection alone; the master trades it for the new token on that connection's next 101
 * and drops the row. Everyone else - including whoever knew the pre-login token - gets a
 * fresh anonymous session instead.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosSessionRotations}).
 * It is written in a worker by the agent that owns the session seam and read in the master
 * on the handshake, which is exactly why it is runtime state rather than a field somewhere:
 * RT sync is what carries it across that process boundary.
 *
 * Rows are written once and never changed - a rotation is what it is - so there is no diff
 * to apply beyond the one an inbound sync brings, and they leave the collection in one of
 * two ways: burned by the exchange, or swept once their moment has passed.
 *
 * The row says which token the browser will answer to and which of its sockets to drop, and
 * nothing about the person behind them. It used to carry the success ack the initiating
 * connection still owed (HIL-423), because the mark lived on that connection and the
 * rotation was what killed it; since HIL-875 the mark lives on the session row, which a
 * rotation renames rather than replaces, so there is nothing left for the ticket to rescue.
 */
final class HilosSessionRotation extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosSessionRotations';

    public const string ticket = 'ticket';
    public const string sessionToken = 'sessionToken';
    public const string acceptKeysToDrop = 'acceptKeysToDrop';
    public const string expiresAtMs = 'expiresAtMs';

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::ticket => 'text',
        self::sessionToken => 'text',
        self::acceptKeysToDrop => 'list',
        self::expiresAtMs => 'float',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::ticket => '',
        self::sessionToken => '',
        self::acceptKeysToDrop => [],
        self::expiresAtMs => 0.0,
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
        self::sessionToken => true,
        self::acceptKeysToDrop => true,
        self::expiresAtMs => true,
    ];

    /** One-time ticket naming this rotation; also the row id. */
    private(set) string $ticket {
        get => isset($this->rowValues()[self::ticket])
            ? $this->rowValues()[self::ticket]->toNative()
            : self::FIELD_DEFAULTS[self::ticket];
        set {
            $this->setRowValue(self::ticket, RtRowValues::fromNative($value));
        }
    }

    /** Session token the ticket's bearer receives on the next handshake. */
    private(set) string $sessionToken {
        get => isset($this->rowValues()[self::sessionToken])
            ? $this->rowValues()[self::sessionToken]->toNative()
            : self::FIELD_DEFAULTS[self::sessionToken];
        set {
            $this->setRowValue(self::sessionToken, RtRowValues::fromNative($value));
        }
    }

    /**
     * @var list<string> Accept keys of the session's other connections, dropped after the exchange
     */
    private(set) array $acceptKeysToDrop {
        get => isset($this->rowValues()[self::acceptKeysToDrop])
            ? $this->rowValues()[self::acceptKeysToDrop]->toNative()
            : self::FIELD_DEFAULTS[self::acceptKeysToDrop];
        set {
            $this->setRowValue(self::acceptKeysToDrop, RtRowValues::fromNative($value));
        }
    }

    /** Unix milliseconds after which the ticket is no longer honoured. */
    private(set) float $expiresAtMs {
        get => $this->rowValues()[self::expiresAtMs] ?? self::FIELD_DEFAULTS[self::expiresAtMs];
        set {
            $this->setRowValue(self::expiresAtMs, $value);
        }
    }

    /**
     * Builds a pending rotation row.
     *
     * @param string $ticket One-time ticket naming the rotation
     * @param string $sessionToken Session token the bearer receives
     * @param list<string> $acceptKeysToDrop Accept keys dropped once the exchange happens
     * @param float $expiresAtMs Unix milliseconds after which the ticket stops being honoured
     * @return static Fresh rotation row
     */
    public static function create(
        string $ticket,
        string $sessionToken,
        array $acceptKeysToDrop,
        float $expiresAtMs,
    ): static {
        $instance = new static();
        $instance->ticket = $ticket;
        $instance->sessionToken = $sessionToken;
        $instance->acceptKeysToDrop = $acceptKeysToDrop;
        $instance->expiresAtMs = $expiresAtMs;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Rotation row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the rotation is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->ticket = self::requireString($row, self::ticket);
        $instance->sessionToken = self::requireString($row, self::sessionToken);
        $instance->acceptKeysToDrop = self::requireStringList($row, self::acceptKeysToDrop);
        $instance->expiresAtMs = self::requireFloat($row, self::expiresAtMs);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * A rotation is never edited after it is written, so this only ever runs for a worker
     * that hydrated the row before the writer finished it. Every field is still accepted:
     * a diff arriving with a field this ignored would be a row silently disagreeing across
     * processes, and the one it would disagree about is the token.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->sessionToken = self::patchString($diff, self::sessionToken, $this->sessionToken);
        $this->acceptKeysToDrop = self::patchStringList($diff, self::acceptKeysToDrop, $this->acceptKeysToDrop);
        $this->expiresAtMs = self::patchFloat($diff, self::expiresAtMs, $this->expiresAtMs);
    }

    /**
     * Tells whether the ticket is still honoured at a given instant.
     *
     * @param float $nowMs Unix milliseconds to judge against
     * @return bool True while the rotation is still live
     */
    public function isLiveAt(float $nowMs): bool
    {
        return $nowMs < $this->expiresAtMs;
    }

    /**
     * @return string Runtime collection key for rotation rows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the one-time ticket
     */
    public function getId(): string
    {
        return $this->ticket;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::ticket => $this->ticket,
            self::sessionToken => $this->sessionToken,
            self::acceptKeysToDrop => $this->acceptKeysToDrop,
            self::expiresAtMs => $this->expiresAtMs,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosSessionRotationRowCodec();
    }

    /**
     * Prepares an unpublished row; the committed-root layer supplies its baseline later.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When a field violates the existing row contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $prepared = yield from self::prepareValues($in, null);
        yield new RtWork();
        $instance = new static();
        $instance->replaceRowValues($prepared);

        return $instance;
    }

    /**
     * Every accepted field is validated before the fixed descriptor is replaced.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate patch
     * @throws InvalidFormatException When a changed field violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded nested-value export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|float|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && !isset(self::PATCH_FIELDS[$key])) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $prepared[$key] = $scalar;
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $prepared[$key] = (float)$scalar;
                    continue;
                }
            }
            if (in_array($type, ['list', '?list', 'array'], true)) {
                $prepared[$key] = yield from self::prepareList($reader);
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }

    /**
     * Strict string lists retain their order and duplicates without a native list.
     *
     * @param RtRowReader $input Incoming array
     * @return Generator<int, RtWork, int, RtRowValues> Validated segmented list
     * @throws InvalidFormatException When a list key or element violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    private static function prepareList(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position
                || $entry->value->reader()->kind() !== RtRowKind::Text) {
                throw new InvalidFormatException('Runtime row carries no list of strings');
            }
            $value = yield from RtRowWriter::copy($entry->value->reader(), $input->arena());
            yield from $writer->write($position++, $value);
        }

        return yield from $writer->finish();
    }
}
