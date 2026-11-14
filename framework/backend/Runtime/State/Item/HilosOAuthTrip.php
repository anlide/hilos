<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosOAuthTripRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosOAuthTrips;
use Hilos\Runtime\View\Actions\Item\HilosOAuthTripActions;
use LogicException;

/**
 * HilosOAuthTrip - one provider sign-in a browser tab is waiting on, and how it ended (HIL-1044).
 *
 * The exchange with a provider runs in agents the browser never talks to, and until this row
 * existed it was remembered only in their memory: an agent that stopped, died or lost the
 * connection it was answering left the tab under a spinner that nothing but a clock could end.
 * The row is what the session holder keeps instead - who is waiting, through which agents the
 * sign-in is going, and, once somebody says so, how it ended - so that every ending reaches the
 * tab as a fact.
 *
 * KEYED BY THE HASH OF A KEY THE TAB MINTED. The tab sends the key with its callback; only the
 * users library ever sees it in the clear, and what travels between agents and sits here is its
 * hash. The key itself is what the tab presents again after a reconnect
 * ({@see HilosOAuthTripActions::readdress()}): an outcome - and above all a sign-in, whose token
 * rotation may only be handed to the tab that started it (HIL-582) - goes to a connection that
 * proves it is that tab, and to nobody else with the same cookie.
 *
 * THE FIRST ENDING WINS ({@see HilosOAuthTripActions::end()}). A failure the holder concluded
 * from a dead agent and a success the agent sent a moment before dying may both arrive; the
 * browser is told one of them, and the other is logged and dropped.
 *
 * Framework-owned runtime state mounted by the sign-in feature ({@see HilosOAuthTrips}) and
 * written by {@see AbstractSessionsLibraryAgent} and nobody else. Runtime rather than durable:
 * a trip lives seconds, and it survives the holder's worker dying because the master keeps a
 * replica and hands it to the next worker - which is exactly the case the holder's start ends
 * with a refusal.
 */
final class HilosOAuthTrip extends RtState
{
    /** Runtime collection key mounted by the sign-in feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosOAuthTrips';

    /** Byte length of the key a tab mints for its trip; the key is these bytes in hex. */
    public const int KEY_BYTES = 16;

    /** Hash algorithm the key is stored and compared under. */
    public const string KEY_HASH_ALGO = 'sha256';

    /**
     * The sign-in succeeded while nobody was listening: the user is known, the session untouched.
     *
     * Not applied on purpose. Binding the session and rotating its token with a dead connection
     * as the initiator would hand the rotation ticket to nobody, and the browser coming back with
     * its old cookie would land in a fresh anonymous session. The grant waits here for the tab to
     * present its key.
     */
    public const string ENDING_GRANTED = 'granted';

    /** The sign-in succeeded and was applied: the session is signed in and its token rotated. */
    public const string ENDING_SIGNED_IN = 'signed_in';

    public const string tripKeyHash = 'tripKeyHash';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string acceptKey = 'acceptKey';
    public const string mode = 'mode';
    public const string provider = 'provider';
    public const string ending = 'ending';
    public const string email = 'email';
    public const string linkToken = 'linkToken';
    public const string accountToken = 'accountToken';
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string updatedAt = 'updatedAt';

    /** Key form: lowercase hex, two characters per byte. */
    private const string KEY_PATTERN = '/\A[0-9a-f]{' . self::KEY_BYTES * 2 . '}\z/';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::tripKeyHash => 'text',
        self::sessionTokenHash => 'text',
        self::acceptKey => 'text',
        self::mode => 'text',
        self::provider => 'text',
        self::ending => '?text',
        self::email => '?text',
        self::linkToken => '?text',
        self::accountToken => '?text',
        self::userId => '?int',
        self::sessionId => '?int',
        self::updatedAt => 'int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::tripKeyHash => '',
        self::sessionTokenHash => '',
        self::acceptKey => '',
        self::mode => '',
        self::provider => '',
        self::ending => null,
        self::email => null,
        self::linkToken => null,
        self::accountToken => null,
        self::userId => null,
        self::sessionId => null,
        self::updatedAt => 0,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::acceptKey => true,
        self::ending => true,
        self::email => true,
        self::linkToken => true,
        self::accountToken => true,
        self::userId => true,
        self::sessionId => true,
        self::updatedAt => true,
    ];

    /** Hash of the key the tab minted; also the row id. */
    private(set) string $tripKeyHash {
        get => isset($this->rowValues()[self::tripKeyHash])
            ? $this->rowValues()[self::tripKeyHash]->toNative()
            : self::FIELD_DEFAULTS[self::tripKeyHash];
        set {
            $this->setRowValue(self::tripKeyHash, RtRowValues::fromNative($value));
        }
    }

    /** Hash of the session cookie token the trip was started under. */
    private(set) string $sessionTokenHash {
        get => isset($this->rowValues()[self::sessionTokenHash])
            ? $this->rowValues()[self::sessionTokenHash]->toNative()
            : self::FIELD_DEFAULTS[self::sessionTokenHash];
        set {
            $this->setRowValue(self::sessionTokenHash, RtRowValues::fromNative($value));
        }
    }

    /** Accept key of the connection the outcome is owed to right now. */
    private(set) string $acceptKey {
        get => isset($this->rowValues()[self::acceptKey]) ? $this->rowValues()[self::acceptKey]->toNative() : self::FIELD_DEFAULTS[self::acceptKey];
        set {
            $this->setRowValue(self::acceptKey, RtRowValues::fromNative($value));
        }
    }

    /** {@see OAuthPendingLogin::MODE_LOGIN} or {@see OAuthPendingLogin::MODE_LINK}. */
    private(set) string $mode {
        get => isset($this->rowValues()[self::mode]) ? $this->rowValues()[self::mode]->toNative() : self::FIELD_DEFAULTS[self::mode];
        set {
            $this->setRowValue(self::mode, RtRowValues::fromNative($value));
        }
    }

    /** Provider key, e.g. 'oauth:github'. */
    private(set) string $provider {
        get => isset($this->rowValues()[self::provider]) ? $this->rowValues()[self::provider]->toNative() : self::FIELD_DEFAULTS[self::provider];
        set {
            $this->setRowValue(self::provider, RtRowValues::fromNative($value));
        }
    }

    /**
     * How the trip ended, or null while it is still going.
     *
     * An {@see OAuthResultSignalData} reason, {@see self::ENDING_GRANTED} or
     * {@see self::ENDING_SIGNED_IN}.
     */
    private(set) ?string $ending {
        get => ($this->rowValues()[self::ending] ?? null)?->toNative();
        set {
            $this->setRowValue(self::ending, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Provider address on a re-authentication or consent ending, null when withheld. */
    private(set) ?string $email {
        get => ($this->rowValues()[self::email] ?? null)?->toNative();
        set {
            $this->setRowValue(self::email, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Signed link capability to redeem after the re-authentication, on that ending alone. */
    private(set) ?string $linkToken {
        get => ($this->rowValues()[self::linkToken] ?? null)?->toNative();
        set {
            $this->setRowValue(self::linkToken, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Signed first sign-in capability, on the consent-required ending alone. */
    private(set) ?string $accountToken {
        get => ($this->rowValues()[self::accountToken] ?? null)?->toNative();
        set {
            $this->setRowValue(self::accountToken, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** User the provider's answer resolved to, on {@see self::ENDING_GRANTED}. */
    private(set) ?int $userId {
        get => $this->rowValues()[self::userId] ?? self::FIELD_DEFAULTS[self::userId];
        set {
            $this->setRowValue(self::userId, $value);
        }
    }

    /** Id of the session row signed in, on {@see self::ENDING_SIGNED_IN}; a rotation keeps it. */
    private(set) ?int $sessionId {
        get => $this->rowValues()[self::sessionId] ?? self::FIELD_DEFAULTS[self::sessionId];
        set {
            $this->setRowValue(self::sessionId, $value);
        }
    }

    /** Epoch milliseconds of the last write, on the server's scale. */
    private(set) int $updatedAt {
        get => $this->rowValues()[self::updatedAt] ?? self::FIELD_DEFAULTS[self::updatedAt];
        set {
            $this->setRowValue(self::updatedAt, $value);
        }
    }

    /**
     * Opens the trip the moment the users library accepted its callback.
     *
     * @param string $tripKeyHash Hash of the key the tab minted
     * @param string $sessionTokenHash Hash of the session cookie token the callback came in under
     * @param string $acceptKey Accept key of the connection that sent the callback
     * @param string $mode Flow mode of the exchange
     * @param string $provider Provider key
     * @param int $updatedAt Epoch milliseconds of this write
     * @return static Fresh trip row, with no ending
     */
    public static function create(
        string $tripKeyHash,
        string $sessionTokenHash,
        string $acceptKey,
        string $mode,
        string $provider,
        int $updatedAt,
    ): static {
        $instance = new static();
        $instance->tripKeyHash = $tripKeyHash;
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->acceptKey = $acceptKey;
        $instance->mode = $mode;
        $instance->provider = $provider;
        $instance->updatedAt = $updatedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Trip row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the trip is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->tripKeyHash = self::requireString($row, self::tripKeyHash);
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->mode = self::requireString($row, self::mode);
        $instance->provider = self::requireString($row, self::provider);
        $instance->ending = self::optionalString($row, self::ending);
        $instance->email = self::optionalString($row, self::email);
        $instance->linkToken = self::optionalString($row, self::linkToken);
        $instance->accountToken = self::optionalString($row, self::accountToken);
        $instance->userId = self::optionalInt($row, self::userId);
        $instance->sessionId = self::optionalInt($row, self::sessionId);
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Refuses a trip key that is not what a tab mints.
     *
     * Checked where the key enters from the wire, so that nothing downstream hashes a value no
     * tab could have produced - an empty key would name the same row for every tab that sent one.
     *
     * @param string $tripKey Key as the browser sent it
     * @throws InvalidFormatException When the value is not {@see self::KEY_BYTES} bytes in lowercase hex
     */
    public static function ensureValidKey(string $tripKey): void
    {
        if (preg_match(self::KEY_PATTERN, $tripKey) !== 1) {
            throw new InvalidFormatException(
                'Invalid OAuth trip key format. Expected ' . self::KEY_BYTES * 2 . ' lowercase hex characters.'
            );
        }
    }

    /**
     * Hashes a trip key into the form the row is kept and found under.
     *
     * @param string $tripKey Key the tab minted, in hex
     * @return string Hash of the key
     */
    public static function hashKey(string $tripKey): string
    {
        return hash(self::KEY_HASH_ALGO, $tripKey);
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Only the addressee and the ending move. The key, the session it was started under, the
     * mode and the provider are what the trip IS; a row that could be re-keyed would let one
     * tab's key open another tab's outcome.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->acceptKey = self::patchString($diff, self::acceptKey, $this->acceptKey);
        $this->ending = self::patchOptionalString($diff, self::ending, $this->ending);
        $this->email = self::patchOptionalString($diff, self::email, $this->email);
        $this->linkToken = self::patchOptionalString($diff, self::linkToken, $this->linkToken);
        $this->accountToken = self::patchOptionalString($diff, self::accountToken, $this->accountToken);
        $this->userId = self::patchOptionalInt($diff, self::userId, $this->userId);
        $this->sessionId = self::patchOptionalInt($diff, self::sessionId, $this->sessionId);
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime collection key for OAuth trips
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the hash of the trip key
     */
    public function getId(): string
    {
        return $this->tripKeyHash;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::tripKeyHash => $this->tripKeyHash,
            self::sessionTokenHash => $this->sessionTokenHash,
            self::acceptKey => $this->acceptKey,
            self::mode => $this->mode,
            self::provider => $this->provider,
            self::ending => $this->ending,
            self::email => $this->email,
            self::linkToken => $this->linkToken,
            self::accountToken => $this->accountToken,
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::updatedAt => $this->updatedAt,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosOAuthTripRowCodec();
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
