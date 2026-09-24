<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosOAuthTrips;
use Hilos\Runtime\View\Actions\Item\HilosOAuthTripActions;

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
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string updatedAt = 'updatedAt';

    /** Key form: lowercase hex, two characters per byte. */
    private const string KEY_PATTERN = '/\A[0-9a-f]{' . self::KEY_BYTES * 2 . '}\z/';

    /** Hash of the key the tab minted; also the row id. */
    private(set) string $tripKeyHash = '';

    /** Hash of the session cookie token the trip was started under. */
    private(set) string $sessionTokenHash = '';

    /** Accept key of the connection the outcome is owed to right now. */
    private(set) string $acceptKey = '';

    /** {@see OAuthPendingLogin::MODE_LOGIN} or {@see OAuthPendingLogin::MODE_LINK}. */
    private(set) string $mode = '';

    /** Provider key, e.g. 'oauth:github'. */
    private(set) string $provider = '';

    /**
     * How the trip ended, or null while it is still going.
     *
     * An {@see OAuthResultSignalData} reason, {@see self::ENDING_GRANTED} or
     * {@see self::ENDING_SIGNED_IN}.
     */
    private(set) ?string $ending = null;

    /** Colliding address to pre-fill for the re-authentication, on that ending alone. */
    private(set) ?string $email = null;

    /** Signed link capability to redeem after the re-authentication, on that ending alone. */
    private(set) ?string $linkToken = null;

    /** User the provider's answer resolved to, on {@see self::ENDING_GRANTED}. */
    private(set) ?int $userId = null;

    /** Id of the session row signed in, on {@see self::ENDING_SIGNED_IN}; a rotation keeps it. */
    private(set) ?int $sessionId = null;

    /** Epoch milliseconds of the last write, on the server's scale. */
    private(set) int $updatedAt = 0;

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
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
