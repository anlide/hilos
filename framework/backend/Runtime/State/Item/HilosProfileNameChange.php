<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosProfileNameChangeRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * One current or last name-change attempt per browser session, owned by the sessions library.
 *
 * Terminal rows remain until the next accepted attempt or the end of this person's session.
 */
final class HilosProfileNameChange extends RtState
{
    public const string RT_COLLECTION = 'hilosProfileNameChanges';

    public const string sessionTokenHash = 'sessionTokenHash';
    public const string userId = 'userId';
    public const string requestId = 'requestId';
    public const string originAcceptKey = 'originAcceptKey';
    public const string name = 'name';
    public const string phase = 'phase';
    public const string refusal = 'refusal';
    public const string startedAt = 'startedAt';
    public const string deadlineAt = 'deadlineAt';

    public const string PHASE_CHECKING = 'checking';
    public const string PHASE_APPLYING = 'applying';
    public const string PHASE_REFUSED = 'refused';
    public const string PHASE_DONE = 'done';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::sessionTokenHash => 'text',
        self::userId => 'int',
        self::requestId => 'text',
        self::originAcceptKey => 'text',
        self::name => 'text',
        self::phase => 'text',
        self::refusal => '?text',
        self::startedAt => 'int',
        self::deadlineAt => '?int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::sessionTokenHash => '',
        self::userId => 0,
        self::requestId => '',
        self::originAcceptKey => '',
        self::name => '',
        self::phase => self::PHASE_CHECKING,
        self::refusal => null,
        self::startedAt => 0,
        self::deadlineAt => null,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::userId => true,
        self::requestId => true,
        self::originAcceptKey => true,
        self::name => true,
        self::phase => true,
        self::refusal => true,
        self::startedAt => true,
        self::deadlineAt => true,
    ];

    /** Hash of the session cookie token. */
    private(set) string $sessionTokenHash {
        get => isset($this->rowValues()[self::sessionTokenHash])
            ? $this->rowValues()[self::sessionTokenHash]->toNative()
            : self::FIELD_DEFAULTS[self::sessionTokenHash];
        set {
            $this->setRowValue(self::sessionTokenHash, RtRowValues::fromNative($value));
        }
    }

    /** Person the attempt belongs to. */
    private(set) int $userId {
        get => $this->rowValues()[self::userId] ?? self::FIELD_DEFAULTS[self::userId];
        set {
            $this->setRowValue(self::userId, $value);
        }
    }

    /** Fresh secure 32-hex attempt identifier. */
    private(set) string $requestId {
        get => isset($this->rowValues()[self::requestId]) ? $this->rowValues()[self::requestId]->toNative() : self::FIELD_DEFAULTS[self::requestId];
        set {
            $this->setRowValue(self::requestId, RtRowValues::fromNative($value));
        }
    }

    /** Connection that submitted the attempt. */
    private(set) string $originAcceptKey {
        get => isset($this->rowValues()[self::originAcceptKey])
            ? $this->rowValues()[self::originAcceptKey]->toNative()
            : self::FIELD_DEFAULTS[self::originAcceptKey];
        set {
            $this->setRowValue(self::originAcceptKey, RtRowValues::fromNative($value));
        }
    }

    /** Trimmed requested name. */
    private(set) string $name {
        get => isset($this->rowValues()[self::name]) ? $this->rowValues()[self::name]->toNative() : self::FIELD_DEFAULTS[self::name];
        set {
            $this->setRowValue(self::name, RtRowValues::fromNative($value));
        }
    }

    /** One of the PHASE_* constants. */
    public string $phase {
        get => isset($this->rowValues()[self::phase]) ? $this->rowValues()[self::phase]->toNative() : self::FIELD_DEFAULTS[self::phase];
        set {
            $this->setRowValue(self::phase, RtRowValues::fromNative($value));
        }
    }

    /** Server refusal sentence, or null. */
    public ?string $refusal {
        get => ($this->rowValues()[self::refusal] ?? null)?->toNative();
        set {
            $this->setRowValue(self::refusal, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Attempt start in epoch milliseconds. */
    private(set) int $startedAt {
        get => $this->rowValues()[self::startedAt] ?? self::FIELD_DEFAULTS[self::startedAt];
        set {
            $this->setRowValue(self::startedAt, $value);
        }
    }

    /** Fixed checking deadline in epoch milliseconds, or null. */
    public ?int $deadlineAt {
        get => $this->rowValues()[self::deadlineAt] ?? self::FIELD_DEFAULTS[self::deadlineAt];
        set {
            $this->setRowValue(self::deadlineAt, $value);
        }
    }

    /**
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param int $userId Person the attempt belongs to
     * @param string $requestId Fresh secure 32-hex attempt identifier
     * @param string $originAcceptKey Connection that submitted the attempt
     * @param string $name Trimmed requested name
     * @param int $startedAt Attempt start in epoch milliseconds
     * @param ?int $deadlineAt Fixed checking deadline in epoch milliseconds, or null
     * @return static New attempt in the checking phase
     */
    public static function create(
        string $sessionTokenHash,
        int $userId,
        string $requestId,
        string $originAcceptKey,
        string $name,
        int $startedAt,
        ?int $deadlineAt,
    ): static {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->userId = $userId;
        $instance->requestId = $requestId;
        $instance->originAcceptKey = $originAcceptKey;
        $instance->name = trim($name);
        $instance->startedAt = $startedAt;
        $instance->deadlineAt = $deadlineAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Restored attempt
     * @throws InvalidFormatException When a required field is absent or a field has the wrong type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->requestId = self::requireString($row, self::requestId);
        $instance->originAcceptKey = self::requireString($row, self::originAcceptKey);
        $instance->name = self::requireString($row, self::name);
        $instance->phase = self::requireString($row, self::phase);
        $instance->refusal = self::optionalString($row, self::refusal);
        $instance->startedAt = self::requireInt($row, self::startedAt);
        $instance->deadlineAt = self::optionalInt($row, self::deadlineAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * The session hash is the immutable row address and is never patched.
     *
     * @param array<string, mixed> $diff Changed fields from runtime sync
     * @throws InvalidFormatException When a changed field has the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->userId = self::patchInt($diff, self::userId, $this->userId);
        $this->requestId = self::patchString($diff, self::requestId, $this->requestId);
        $this->originAcceptKey = self::patchString($diff, self::originAcceptKey, $this->originAcceptKey);
        $this->name = self::patchString($diff, self::name, $this->name);
        $this->phase = self::patchString($diff, self::phase, $this->phase);
        $this->refusal = self::patchOptionalString($diff, self::refusal, $this->refusal);
        $this->startedAt = self::patchInt($diff, self::startedAt, $this->startedAt);
        $this->deadlineAt = self::patchOptionalInt($diff, self::deadlineAt, $this->deadlineAt);
    }

    /** @return string Runtime sync collection key */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return string Hash of the session cookie token */
    public function getId(): string
    {
        return $this->sessionTokenHash;
    }

    /** @return array<string, mixed> Full runtime sync row */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
            self::userId => $this->userId,
            self::requestId => $this->requestId,
            self::originAcceptKey => $this->originAcceptKey,
            self::name => $this->name,
            self::phase => $this->phase,
            self::refusal => $this->refusal,
            self::startedAt => $this->startedAt,
            self::deadlineAt => $this->deadlineAt,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosProfileNameChangeRowCodec();
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
     * @return Generator<int, RtWork, int, void> Bounded scalar/text export
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
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }
}
