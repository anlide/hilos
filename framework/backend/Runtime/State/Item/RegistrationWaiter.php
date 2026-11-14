<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RegistrationWaiterRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * RegistrationWaiter - one session parked on the code step of a registration (HIL-415).
 *
 * Converge is a property of the whole sign-in surface, not a trick of registration:
 * a step can be rebuilt by an event nobody in that browser caused. This row is what
 * makes the registration case of it possible - it names the connections waiting on
 * an identifier's confirmation code, so when the code comes back from ANY of them
 * (or when the hold expires and nobody's did), every one of them can be moved:
 * to the done step signed in, or back to the identifier step.
 *
 * Keyed by accept key, not by identifier: the broadcast goes out per connection,
 * and a dropped connection must remove exactly one row. Several rows therefore
 * carry the same identifier, which is the normal state of a converged address -
 * two tabs, two devices, two people who both typed it.
 *
 * Transient by nature and by design. It is worth nothing after a restart (the
 * sockets are gone too), which is exactly why the reservation it waits on is
 * durable and this is not ({@see RegistrationReservationService}). The owning
 * agent's tick prunes rows whose connection is no longer live, so a browser closed
 * on the code screen leaves nothing behind.
 */
final class RegistrationWaiter extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosRegistrationWaiters';

    public const string acceptKey = 'acceptKey';
    public const string identifier = 'identifier';
    public const string sessionToken = 'sessionToken';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::acceptKey => 'text',
        self::identifier => 'text',
        self::sessionToken => 'text',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::acceptKey => '',
        self::identifier => '',
        self::sessionToken => '',
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::identifier => true,
        self::sessionToken => true,
    ];

    /** Accept key of the waiting connection; also this row's id and the broadcast target. */
    private(set) string $acceptKey {
        get => isset($this->rowValues()[self::acceptKey]) ? $this->rowValues()[self::acceptKey]->toNative() : self::FIELD_DEFAULTS[self::acceptKey];
        set {
            $this->setRowValue(self::acceptKey, RtRowValues::fromNative($value));
        }
    }

    /** Normalized identifier whose confirmation the connection is waiting for. */
    public string $identifier {
        get => isset($this->rowValues()[self::identifier])
            ? $this->rowValues()[self::identifier]->toNative()
            : self::FIELD_DEFAULTS[self::identifier];
        set {
            $this->setRowValue(self::identifier, RtRowValues::fromNative($value));
        }
    }

    /** Session token to sign in when somebody's code confirms the registration. */
    public string $sessionToken {
        get => isset($this->rowValues()[self::sessionToken])
            ? $this->rowValues()[self::sessionToken]->toNative()
            : self::FIELD_DEFAULTS[self::sessionToken];
        set {
            $this->setRowValue(self::sessionToken, RtRowValues::fromNative($value));
        }
    }

    /**
     * Parks a connection on the code step of an identifier.
     *
     * @param string $acceptKey Accept key of the waiting connection (row id)
     * @param string $identifier Normalized identifier being confirmed (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
     * @return static Fresh waiter row
     */
    public static function create(string $acceptKey, string $identifier, string $sessionToken): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->identifier = $identifier;
        $instance->sessionToken = $sessionToken;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Waiter row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the waiter is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->identifier = self::requireString($row, self::identifier);
        $instance->sessionToken = self::requireString($row, self::sessionToken);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for parked registration sessions
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * Re-points an already parked connection at another identifier.
     *
     * The base body is empty, so without this the in-place re-park writes nothing —
     * here and on every worker the sync replays it to. A connection that goes back
     * and submits a different address would keep waiting under the first one and be
     * signed into whatever account THAT one produces. The accept key is the row id
     * and never moves.
     *
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     * @throws InvalidFormatException When the diff carries a field as anything but a string
     */
    public function applyDiff(array $diff): void
    {
        $this->identifier = self::patchString($diff, self::identifier, $this->identifier);
        $this->sessionToken = self::patchString($diff, self::sessionToken, $this->sessionToken);
    }

    /**
     * @return string Runtime row id, the waiting connection's accept key
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::identifier => $this->identifier,
            self::sessionToken => $this->sessionToken,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new RegistrationWaiterRowCodec();
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
