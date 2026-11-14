<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\OAuthPendingLoginRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * OAuthPendingLogin - one in-flight OAuth login exchange, keyed by accept key (HIL-281).
 *
 * The internal RT op the OAuth login mechanism (mechanism B) hangs its non-blocking
 * work on. The callback action verifies the signed state synchronously (no I/O) and,
 * on success, records one of these keyed by the initiating connection's accept key,
 * then returns — its `action_success` means only "accepted, working", not "logged in".
 * The framework-owned OAuth async agent
 * ({@see AbstractOAuthAgent}) observes the collection and
 * drives the token/userinfo round-trips across ticks off the master.
 *
 * It carries no deadline (HIL-1044): each of its provider requests is bounded by its
 * own timeout, and every other ending - a stop, a dead agent - is a fact the session
 * holder is told. It is an INTERNAL op, not a browser-gated table item — nothing here
 * is surfaced to a page.
 */
final class OAuthPendingLogin extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosOAuthPendingLogins';

    /** Flow mode: a plain sign-in exchange (the default). */
    public const string MODE_LOGIN = 'login';

    /** Flow mode: linking the resolved identity to {@see linkUserId} (HIL-401). */
    public const string MODE_LINK = 'link';

    public const string acceptKey = 'acceptKey';
    public const string sessionToken = 'sessionToken';
    public const string provider = 'provider';
    public const string code = 'code';
    public const string tripKeyHash = 'tripKeyHash';
    public const string mode = 'mode';
    public const string linkUserId = 'linkUserId';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::acceptKey => 'text',
        self::sessionToken => 'text',
        self::provider => 'text',
        self::code => 'text',
        self::tripKeyHash => 'text',
        self::mode => 'text',
        self::linkUserId => 'int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::acceptKey => '',
        self::sessionToken => '',
        self::provider => '',
        self::code => '',
        self::tripKeyHash => '',
        self::mode => self::MODE_LOGIN,
        self::linkUserId => 0,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
    ];

    /** Accept key of the initiating connection; also this op's id and the failure target. */
    private(set) string $acceptKey {
        get => isset($this->rowValues()[self::acceptKey]) ? $this->rowValues()[self::acceptKey]->toNative() : self::FIELD_DEFAULTS[self::acceptKey];
        set {
            $this->setRowValue(self::acceptKey, RtRowValues::fromNative($value));
        }
    }

    /** Session token to authenticate on success (the connection's live session). */
    public string $sessionToken {
        get => isset($this->rowValues()[self::sessionToken])
            ? $this->rowValues()[self::sessionToken]->toNative()
            : self::FIELD_DEFAULTS[self::sessionToken];
        set {
            $this->setRowValue(self::sessionToken, RtRowValues::fromNative($value));
        }
    }

    /** Provider key, e.g. 'oauth:github'. */
    public string $provider {
        get => isset($this->rowValues()[self::provider]) ? $this->rowValues()[self::provider]->toNative() : self::FIELD_DEFAULTS[self::provider];
        set {
            $this->setRowValue(self::provider, RtRowValues::fromNative($value));
        }
    }

    /** Authorization code returned to the SPA callback, exchanged for a token. */
    public string $code {
        get => isset($this->rowValues()[self::code]) ? $this->rowValues()[self::code]->toNative() : self::FIELD_DEFAULTS[self::code];
        set {
            $this->setRowValue(self::code, RtRowValues::fromNative($value));
        }
    }

    /** Hash of the key the tab minted; names the trip every ending of this op is reported under (HIL-1044). */
    public string $tripKeyHash {
        get => isset($this->rowValues()[self::tripKeyHash])
            ? $this->rowValues()[self::tripKeyHash]->toNative()
            : self::FIELD_DEFAULTS[self::tripKeyHash];
        set {
            $this->setRowValue(self::tripKeyHash, RtRowValues::fromNative($value));
        }
    }

    /** Flow mode this op resolves under ({@see MODE_LOGIN} or {@see MODE_LINK}, HIL-401). */
    public string $mode {
        get => isset($this->rowValues()[self::mode]) ? $this->rowValues()[self::mode]->toNative() : self::FIELD_DEFAULTS[self::mode];
        set {
            $this->setRowValue(self::mode, RtRowValues::fromNative($value));
        }
    }

    /** User the resolved identity is linked to under {@see MODE_LINK}; 0 for a login exchange. */
    public int $linkUserId {
        get => $this->rowValues()[self::linkUserId] ?? self::FIELD_DEFAULTS[self::linkUserId];
        set {
            $this->setRowValue(self::linkUserId, $value);
        }
    }

    /**
     * Builds a pending-login op for a verified callback.
     *
     * @param string $acceptKey Initiating connection accept key (op id + failure target)
     * @param string $sessionToken Session token to authenticate on success
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $code Authorization code to exchange
     * @param string $tripKeyHash Hash of the key the tab minted for this exchange
     * @param string $mode Flow mode ({@see MODE_LOGIN} default, {@see MODE_LINK})
     * @param int $linkUserId User the identity links to under {@see MODE_LINK}; 0 for login
     * @return static Fresh pending-login op
     */
    public static function create(
        string $acceptKey,
        string $sessionToken,
        string $provider,
        string $code,
        string $tripKeyHash,
        string $mode = self::MODE_LOGIN,
        int $linkUserId = 0,
    ): static {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->sessionToken = $sessionToken;
        $instance->provider = $provider;
        $instance->code = $code;
        $instance->tripKeyHash = $tripKeyHash;
        $instance->mode = $mode;
        $instance->linkUserId = $linkUserId;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Pending-login op restored from a sync row
     * @throws InvalidFormatException When the row lost a field the op is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->sessionToken = self::requireString($row, self::sessionToken);
        $instance->provider = self::requireString($row, self::provider);
        $instance->code = self::requireString($row, self::code);
        $instance->tripKeyHash = self::requireString($row, self::tripKeyHash);
        $instance->mode = self::requireString($row, self::mode);
        $instance->linkUserId = self::requireInt($row, self::linkUserId);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for pending OAuth login ops
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the initiating connection accept key
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
            self::sessionToken => $this->sessionToken,
            self::provider => $this->provider,
            self::code => $this->code,
            self::tripKeyHash => $this->tripKeyHash,
            self::mode => $this->mode,
            self::linkUserId => $this->linkUserId,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new OAuthPendingLoginRowCodec();
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
