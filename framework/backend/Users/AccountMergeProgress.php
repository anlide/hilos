<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Object\Item\User as ObjectUser;
use JsonException;

/** Allowlisted observation of a saved merge, independent of its handover copy and live participants. */
final readonly class AccountMergeProgress
{
    public const string operationId = 'operationId';
    public const string survivorUserId = 'survivorUserId';
    public const string loserUserId = 'loserUserId';
    public const string state = 'state';
    public const string stage = 'stage';
    public const string outcome = 'outcome';
    public const string given = 'given';
    public const string received = 'received';
    public const string choice = 'choice';
    public const string refusal = 'refusal';
    public const string code = 'code';
    public const string reason = 'reason';
    public const string parts = 'parts';
    public const string waits = 'waits';
    public const string participant = 'participant';
    public const string owner = 'owner';
    public const string userId = 'userId';
    public const string done = 'done';

    public const string PREPARING = 'preparing';
    public const string WAIT = 'wait';
    public const string REFUSAL = 'refusal';
    public const string END = 'end';

    /**
     * @param int $operationId Saved operation id
     * @param int $survivorUserId Saved survivor id
     * @param int $loserUserId Saved loser id
     * @param string $state Observation state
     * @param string $stage Physical operation stage
     * @param ?string $outcome Durable outcome, null until finish
     * @param bool $given Strict give result exists
     * @param bool $received Strict receive result exists
     * @param AccountMergeChoice $choice Saved choices, without current authentication state
     * @param ?array{code: string, reason: string} $refusal Server refusal sentence
     * @param list<array{participant: string, owner: string, userId: int, done: bool}> $parts Recorded duties in assignment id order
     * @param list<array{participant: string, userId: int}> $waits Outstanding recorded waits
     * @throws InvalidFormatException When identities, state, outcome or nested allowlists are invalid
     */
    public function __construct(
        public int $operationId,
        public int $survivorUserId,
        public int $loserUserId,
        public string $state,
        public string $stage,
        public ?string $outcome,
        public bool $given,
        public bool $received,
        public AccountMergeChoice $choice,
        public ?array $refusal,
        public array $parts,
        public array $waits,
    ) {
        if ($operationId <= 0 || $survivorUserId <= 0 || $loserUserId <= 0 || $survivorUserId === $loserUserId
            || AccountMergeStage::tryFrom($stage) === null || !in_array($state, [self::PREPARING, self::WAIT, self::REFUSAL, self::END], true)
            || ($outcome !== null && AccountOperationOutcome::tryFrom($outcome) === null)
            || ($outcome === AccountOperationOutcome::Completed->value && $stage !== AccountMergeStage::Go->value)
            || ($outcome === AccountOperationOutcome::NotStarted->value && $stage !== AccountMergeStage::Cancelling->value)
            || (($state === self::REFUSAL) !== ($refusal !== null))
            || ($state === self::END && $outcome === null)
            || (in_array($state, [self::PREPARING, self::WAIT], true) && $outcome !== null)
            || ($state === self::PREPARING && $stage !== AccountMergeStage::Preparing->value)
            || ($state === self::WAIT && $stage === AccountMergeStage::Preparing->value)) {
            throw new InvalidFormatException('Merge progress requires a distinct positive pair and consistent saved state and outcome.');
        }
        if ($refusal !== null) {
            self::requireKeys($refusal, [self::code, self::reason]);
            if (!is_string($refusal[self::code]) || AccountMergeRefusal::tryFrom($refusal[self::code]) === null
                || !is_string($refusal[self::reason]) || $refusal[self::reason] === '') {
                throw new InvalidFormatException('Merge progress requires a known refusal and its server sentence.');
            }
        }
        foreach ([$parts, $waits] as $rows) {
            if (!array_is_list($rows)) {
                throw new InvalidFormatException('Merge progress parts and waits must be lists.');
            }
        }
        foreach ($parts as $part) {
            self::requireKeys($part, [self::participant, self::owner, self::userId, self::done]);
            self::requireParticipant($part);
            if (!is_string($part[self::owner]) || $part[self::owner] === '' || !is_bool($part[self::done])) {
                throw new InvalidFormatException('Merge progress parts require an owner type and a boolean saved-result flag.');
            }
        }
        foreach ($waits as $wait) {
            self::requireKeys($wait, [self::participant, self::userId]);
            self::requireParticipant($wait);
        }
    }

    /** @return array<string, mixed> Exact nonsecret browser projection */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::survivorUserId => $this->survivorUserId,
            self::loserUserId => $this->loserUserId,
            self::state => $this->state,
            self::stage => $this->stage,
            self::outcome => $this->outcome,
            self::given => $this->given,
            self::received => $this->received,
            self::choice => [
                AccountMergeChoice::passwordFate => $this->choice->passwordFate?->value,
                AccountMergeChoice::secondFactorFate => $this->choice->secondFactorFate?->value,
                AccountMergeChoice::expectedSurvivorHasSecondFactor => $this->choice->expectedSurvivorHasSecondFactor,
                AccountMergeChoice::expectedLoserHasSecondFactor => $this->choice->expectedLoserHasSecondFactor,
            ],
            self::refusal => $this->refusal,
            self::parts => $this->parts,
            self::waits => $this->waits,
        ];
    }

    /**
     * @param array<string, mixed> $data Exact browser projection
     * @return self Checked allowlist, without coercion or extra nested fields
     * @throws InvalidFormatException When required fields or nested contracts are invalid
     * @throws InvalidJsonException When choices cannot be decoded
     * @throws JsonException When choices cannot be encoded
     */
    public static function fromArray(array $data): self
    {
        self::requireKeys($data, [self::operationId, self::survivorUserId, self::loserUserId, self::state, self::stage,
            self::outcome, self::given, self::received, self::choice, self::refusal, self::parts, self::waits]);
        foreach ([self::operationId, self::survivorUserId, self::loserUserId] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('Merge progress ids must be integers.');
            }
        }
        if (!is_string($data[self::state]) || !is_string($data[self::stage])
            || ($data[self::outcome] !== null && !is_string($data[self::outcome]))
            || !is_bool($data[self::given]) || !is_bool($data[self::received]) || !is_array($data[self::choice])
            || ($data[self::refusal] !== null && !is_array($data[self::refusal]))
            || !is_array($data[self::parts]) || !is_array($data[self::waits])) {
            throw new InvalidFormatException('Merge progress fields must have their declared types.');
        }

        return new self(
            $data[self::operationId], $data[self::survivorUserId], $data[self::loserUserId],
            $data[self::state], $data[self::stage], $data[self::outcome], $data[self::given], $data[self::received],
            AccountMergeChoice::fromJson(json_encode($data[self::choice], JSON_THROW_ON_ERROR)),
            $data[self::refusal], $data[self::parts], $data[self::waits],
        );
    }

    /** @return array<string, WireField> Column origins and derived, nonpersonal progress fields */
    public static function wireFields(): array
    {
        return [
            self::operationId => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::id),
            self::survivorUserId => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::root_user_id),
            self::loserUserId => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::loser_user_id),
            self::stage => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::stage),
            self::outcome => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::outcome),
            self::choice => WireField::column(HilosDbContext::accountOperations, EntityAccountOperation::merge_choice),
            self::state => WireField::notPersonal(),
            self::given => WireField::notPersonal(),
            self::received => WireField::notPersonal(),
            self::parts => WireField::each([
                self::participant => WireField::notPersonal(),
                self::owner => WireField::notPersonal(),
                self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
                self::done => WireField::notPersonal(),
            ]),
            self::waits => WireField::each([
                self::participant => WireField::notPersonal(),
                self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
            ]),
        ];
    }

    /**
     * @param mixed $row Boundary object fields
     * @param list<string> $keys Complete allowlist
     * @throws InvalidFormatException When fields are missing, additional or not an object
     */
    private static function requireKeys(mixed $row, array $keys): void
    {
        if (!is_array($row) || count($row) !== count($keys) || array_diff($keys, array_keys($row)) !== []) {
            throw new InvalidFormatException('Merge progress requires exactly its declared fields.');
        }
    }

    /**
     * @param array<string, mixed> $row Part or wait at the wire boundary
     * @throws InvalidFormatException When the participant or account id is invalid
     */
    private static function requireParticipant(array $row): void
    {
        if (!is_string($row[self::participant]) || $row[self::participant] === ''
            || !is_int($row[self::userId]) || $row[self::userId] <= 0) {
            throw new InvalidFormatException('Merge progress requires a named participant and a positive account id.');
        }
    }
}
