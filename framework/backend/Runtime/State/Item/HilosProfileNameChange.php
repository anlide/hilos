<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;

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

    /** Hash of the session cookie token. */
    private(set) string $sessionTokenHash = '';

    /** Person the attempt belongs to. */
    private(set) int $userId = 0;

    /** Fresh secure 32-hex attempt identifier. */
    private(set) string $requestId = '';

    /** Connection that submitted the attempt. */
    private(set) string $originAcceptKey = '';

    /** Trimmed requested name. */
    private(set) string $name = '';

    /** One of the PHASE_* constants. */
    public string $phase = self::PHASE_CHECKING;

    /** Server refusal sentence, or null. */
    public ?string $refusal = null;

    /** Attempt start in epoch milliseconds. */
    private(set) int $startedAt = 0;

    /** Fixed checking deadline in epoch milliseconds, or null. */
    public ?int $deadlineAt = null;

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
}
