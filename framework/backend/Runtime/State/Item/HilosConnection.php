<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\HilosException;
use Hilos\Runtime\Row\HilosConnectionRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Utils\Helpers\TimeHelper;
use LogicException;
use Throwable;

/**
 * Presence-stage connection row, composed in base → session → project order.
 *
 * Projects implement four yielding hooks over the shared fixed descriptor. Variable
 * fields hold page leases; native property reads and the synchronous adapters are
 * explicit compatibility doors. The session stage adds sessionToken and sessionId.
 */
abstract class HilosConnection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';
    public const string probablyOffline = 'probablyOffline';

    /** Full base-stage schema; the session stage extends it for legacy ingress. */
    protected const array BASE_FIELD_TYPES = [
        self::acceptKey => 'text',
        self::userId => '?int',
        self::connectedAt => 'int',
        self::probablyOffline => 'bool',
    ];

    /** Synchronous defaults, never defaults for missing full-row keys. */
    protected const array BASE_FIELD_DEFAULTS = [
        self::acceptKey => '',
        self::userId => null,
        self::connectedAt => 0,
        self::probablyOffline => false,
    ];

    /** Project fields accepted at the legacy array boundary. */
    protected const array OWN_FIELD_TYPES = [];

    /** WebSocket accept key (immutable collection id). */
    private(set) string $acceptKey {
        get => isset($this->rowValues()[self::acceptKey])
            ? $this->rowValues()[self::acceptKey]->toNative()
            : self::BASE_FIELD_DEFAULTS[self::acceptKey];
        set {
            $this->setRowValue(self::acceptKey, RtRowValues::fromNative($value));
        }
    }

    /** Authenticated user id, or null for an anonymous socket. */
    public ?int $userId {
        get => $this->rowValues()[self::userId] ?? null;
        set {
            $this->setRowValue(self::userId, $value);
        }
    }

    /** UTC epoch milliseconds when the socket opened. */
    private(set) int $connectedAt {
        get => $this->rowValues()[self::connectedAt] ?? self::BASE_FIELD_DEFAULTS[self::connectedAt];
        set {
            $this->setRowValue(self::connectedAt, $value);
        }
    }

    /** Whether the socket is silent past its liveness window, while still registered. */
    public bool $probablyOffline {
        get => $this->rowValues()[self::probablyOffline] ?? self::BASE_FIELD_DEFAULTS[self::probablyOffline];
        set {
            $this->setRowValue(self::probablyOffline, $value);
        }
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Authenticated user id
     * @return static Fresh connection with the legacy baseline
     * @throws HilosException When project initialization fails
     */
    public static function create(string $acceptKey, ?int $userId): static
    {
        try {
            $instance = new static();
            $instance->initBase($acceptKey, $userId);
            RtWorkDriver::runSynchronously($instance->initOwn());
            $instance->markRtSyncBaseline();

            return $instance;
        } catch (HilosException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FrameworkLogicException('Connection compatibility operation failed', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $row Native compatibility row
     * @return static Restored connection with the legacy baseline
     * @throws HilosException When a base or project field violates its existing contract
     */
    final public static function fromRow(array $row): static
    {
        try {
            $input = self::legacyReader($row);
            $instance = RtWorkDriver::runSynchronously(static::fromValues($input));
            $instance->retainResidentValues();
            $instance->markRtSyncBaseline();

            return $instance;
        } catch (HilosException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FrameworkLogicException('Connection compatibility operation failed', previous: $exception);
        }
    }

    /** @return string Immutable accept key */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Explicit native compatibility export
     */
    final public function toArray(): array
    {
        $row = [];
        foreach (array_keys(static::BASE_FIELD_TYPES + static::OWN_FIELD_TYPES) as $key) {
            $row[$key] = $this->$key;
        }

        return $row;
    }

    /**
     * The legacy door keeps its sequential mutation semantics; patchValues is atomic.
     *
     * @param array<string, mixed> $diff Native changed fields
     * @throws HilosException When a changed field violates its existing contract
     */
    final public function applyDiff(array $diff): void
    {
        try {
            unset($diff[self::acceptKey]);
            $input = self::legacyReader($diff);
            RtWorkDriver::runSynchronously($this->applyBaseDiff($input));
            RtWorkDriver::runSynchronously($this->applyOwnDiff($input));
        } catch (HilosException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FrameworkLogicException('Connection compatibility operation failed', previous: $exception);
        } finally {
            $this->retainResidentValues();
        }
    }

    /** @return RtRowCodec Codec bound to the concrete project subclass */
    final public function rowCodec(): RtRowCodec
    {
        return new HilosConnectionRowCodec(static::class);
    }

    /**
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Unpublished validated row without a baseline
     * @throws HilosException When base or project validation refuses the row
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    final public static function fromValues(RtRowReader $in): Generator
    {
        yield new RtWork();
        $instance = new static();
        yield from $instance->hydrateBase($in);
        yield from $instance->hydrateOwn($in);

        return $instance;
    }

    /**
     * @param RtRowWriter $out Unpublished segmented destination
     * @return Generator<int, RtWork, int, void> Ordered base, session and project export
     * @throws HilosException When a project hook or stored value refuses export
     * @throws LogicException When the destination has closed
     */
    final public function exportValues(RtRowWriter $out): Generator
    {
        $captured = clone $this;

        return (static function () use ($captured, $out): Generator {
            yield from $captured->exportBase($out);
            yield from $captured->exportOwn($out);
        })();
    }

    /**
     * Only the final fixed descriptor swap changes the target, after all hooks validate.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Complete atomic draft patch
     * @throws HilosException When a base or project field is invalid
     * @throws LogicException When page preparation violates writer lifetime
     */
    final public function patchValues(RtRowReader $diff): Generator
    {
        yield new RtWork();
        $candidate = clone $this;
        yield from $candidate->applyBaseDiff($diff);
        yield from $candidate->applyOwnDiff($diff);
        yield new RtWork();
        $this->replaceRowValues($candidate->rowValues());
    }

    /** @return array<string, mixed> Presence and session property defaults in export order */
    protected function rowFieldDefaults(): array
    {
        return static::BASE_FIELD_DEFAULTS;
    }

    /** @return Generator<int, RtWork, int, void> Seeds project fields after both base stages */
    abstract protected function initOwn(): Generator;

    /**
     * @param RtRowReader $row Segmented full row
     * @return Generator<int, RtWork, int, void> Hydrates project fields into the fixed descriptor
     * @throws HilosException When project fields violate their contract
     * @throws LogicException When page preparation violates writer lifetime
     */
    abstract protected function hydrateOwn(RtRowReader $row): Generator;

    /**
     * @param RtRowWriter $out Destination after the base stages
     * @return Generator<int, RtWork, int, void> Exports project fields without materialization
     * @throws HilosException When a stored project value is malformed
     * @throws LogicException When the destination has closed
     */
    abstract protected function exportOwn(RtRowWriter $out): Generator;

    /**
     * @param RtRowReader $diff Segmented changed fields; omission preserves the current value
     * @return Generator<int, RtWork, int, void> Applies project fields to the shared fixed descriptor
     * @throws HilosException When a changed project field violates its contract
     * @throws LogicException When page preparation violates writer lifetime
     */
    abstract protected function applyOwnDiff(RtRowReader $diff): Generator;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Authenticated user id
     */
    protected function initBase(string $acceptKey, ?int $userId): void
    {
        $this->acceptKey = $acceptKey;
        $this->userId = $userId;
        $this->connectedAt = TimeHelper::nowMs();
        $this->probablyOffline = false;
    }

    /**
     * @param RtRowReader $row Segmented full row
     * @return Generator<int, RtWork, int, void> Hydrates the presence stage
     * @throws InvalidFormatException When a presence field is missing or mistyped
     * @throws LogicException When page preparation violates writer lifetime
     */
    protected function hydrateBase(RtRowReader $row): Generator
    {
        yield from $this->readFields($row, self::BASE_FIELD_TYPES);
    }

    /**
     * @param RtRowWriter $out Segmented destination
     * @return Generator<int, RtWork, int, void> Exports the presence stage
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the destination has closed
     */
    protected function exportBase(RtRowWriter $out): Generator
    {
        yield from $this->exportFields($out, self::BASE_FIELD_DEFAULTS);
    }

    /**
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Applies mutable presence fields, preserving the accept key
     * @throws InvalidFormatException When a changed field is mistyped
     * @throws LogicException When page preparation violates writer lifetime
     */
    protected function applyBaseDiff(RtRowReader $diff): Generator
    {
        yield from $this->readFields($diff, [
            self::userId => '?int',
            self::connectedAt => 'int',
            self::probablyOffline => 'bool',
        ], true);
    }

    /**
     * Schemas are fixed class metadata; the only variable members are page leases.
     *
     * @param RtRowReader $input Segmented row or patch
     * @param array<string, string> $types Fixed field types for one stage
     * @param bool $patch Whether absent keys preserve their previous value
     * @return Generator<int, RtWork, int, void> Bounded type validation and segmented storage
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When segmented copying violates writer lifetime
     */
    final protected function readFields(RtRowReader $input, array $types, bool $patch = false): Generator
    {
        foreach ($types as $key => $type) {
            yield new RtWork();
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($patch) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $this->setRowValue($key, null);
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $this->setRowValue($key, null);
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $this->setRowValue($key, $scalar);
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $this->setRowValue($key, (float)$scalar);
                    continue;
                }
            }
            if ($type === 'strings' && $reader->kind() === RtRowKind::Map) {
                $cursor = $reader->entries();
                $index = 0;
                while (($entry = yield from $cursor->advance()) !== null) {
                    if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                        || (yield from $entry->key->reader()->scalar()) !== $index++
                        || $entry->value->reader()->kind() !== RtRowKind::Text
                    ) {
                        throw new InvalidFormatException('Runtime row must carry a list of strings under key ' . $key);
                    }
                }
            } elseif ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $this->setRowValue($key, yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate));
        }
    }

    /**
     * @param RtRowWriter $out Unpublished destination
     * @param array<string, mixed> $defaults Fixed native defaults of this stage's fields
     * @return Generator<int, RtWork, int, void> Bounded export of stored pages and scalar defaults
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the destination has closed
     */
    final protected function exportFields(RtRowWriter $out, array $defaults): Generator
    {
        foreach ($defaults as $key => $default) {
            yield new RtWork();
            $value = $this->rowValues()[$key] ?? $default;
            $encoded = $value instanceof RtRowValues
                ? yield from RtRowWriter::copy($value->reader(), $out->arena())
                : yield from RtRowWriter::encode($value, $out->arena());
            yield from $out->write($key, $encoded);
        }
    }

    /**
     * @param array<string, mixed> $row Explicit native compatibility boundary
     * @return RtRowReader Segmented declared fields, ignoring unrelated native values
     */
    private static function legacyReader(array $row): RtRowReader
    {
        $types = static::BASE_FIELD_TYPES + static::OWN_FIELD_TYPES;
        $known = array_intersect_key($row, $types);
        foreach ($known as $key => $value) {
            // Keep unsupported native graphs invalid without refusing ahead of their field:
            // legacy patches must still apply the valid fields preceding that refusal.
            if ($types[$key] === 'strings') {
                if (!is_array($value) || !array_is_list($value)) {
                    $known[$key] = false;
                    continue;
                }
                foreach ($value as $element) {
                    if (!is_string($element)) {
                        $known[$key] = false;
                        break;
                    }
                }
            } elseif ($value !== null && !is_scalar($value)) {
                $known[$key] = [];
            }
        }

        return RtRowValues::fromNative($known)->reader();
    }

    /** Legacy writes are resident immediately, including fields ahead of a refused patch. */
    private function retainResidentValues(): void
    {
        try {
            foreach ($this->rowValues() as $key => $value) {
                if ($value instanceof RtRowValues) {
                    $this->setRowValue($key, $value->retain(RtRowRole::Resident));
                }
            }
        } catch (LogicException $exception) {
            throw new FrameworkLogicException('Connection resident value lease is closed', previous: $exception);
        }
    }
}
