<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergeProjectOwner;
use Hilos\Auth\AccountOperation\AccountMergeProjectStep;
use Hilos\Auth\AccountOperation\AccountMergeReceiveResult;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\Exception\AccountMergeGiveWaitsException;
use Hilos\Auth\AccountOperation\Exception\AccountMergeReceiveWaitsException;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DbSyncApplicator;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use RuntimeException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Object\Objects;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Logger;
use Hilos\Users\AccountErasure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Assert;
use Throwable;

require_once __DIR__ . '/AccountMergeProjectParticipantIntegrationTest.php';

/** Real owner handlers and commits, with serialized transport frames held or delivered explicitly. */
final class AccountMergeCoordinatorIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int SURVIVOR = 7;
    private const int LOSER = 9;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private MergeCoordinatorHolder $holder;
    private ?MergeCoordinatorProjectOwner $project = null;
    private string $previousApp;
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->stubs(true);
        $this->stubs(false);
        Database::sqlRun('CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        $this->freshContext();
        MergeCoordinatorHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->holder = new MergeCoordinatorHolder();
        OwnershipDeclaration::claimAll($this->holder);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (11, 'Other'), (13, 'Peer')");
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
            $this->stubs(true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            if ($this->project !== null) {
                TruthSourceRegistry::unregisterAgent($this->project->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->project->getId()));
            }
            Hilos::$sr = $this->previousRouter;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /**
     * @param string $userId Person addressed by the registered indexed route
     * @return AbstractUserAgent Registered personal owner with the production handler
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeCoordinatorUser($userId);
    }

    /** @return iterable<string, array{?PasswordFate, bool, bool, bool, ?int, ?int}> Fate, password presence, proof, link owner, kept owner */
    public static function passwordAddressChoices(): iterable
    {
        yield 'keep survivor' => [PasswordFate::SURVIVOR, true, true, true, null, self::SURVIVOR];
        yield 'keep loser' => [PasswordFate::LOSER, true, true, true, null, self::LOSER];
        yield 'keep neither' => [PasswordFate::NONE, true, true, true, null, null];
        yield 'drop unproven survivor' => [PasswordFate::LOSER, true, true, false, null, self::LOSER];
        yield 'drop both unproven' => [PasswordFate::NONE, true, true, false, null, null];
        yield 'source link collision' => [PasswordFate::SURVIVOR, true, true, true, 11, self::SURVIVOR];
        yield 'survivor link collision' => [PasswordFate::SURVIVOR, true, true, true, self::SURVIVOR, self::SURVIVOR];
        yield 'name absent survivor' => [PasswordFate::SURVIVOR, false, true, true, null, null];
        yield 'automatic source password' => [null, false, true, true, null, self::LOSER];
        yield 'automatic target password' => [null, true, false, true, null, self::SURVIVOR];
    }

    /**
     * @param ?PasswordFate $fate Immutable password choice
     * @param bool $target Whether the survivor starts with a password
     * @param bool $source Whether the loser starts with a password
     * @param bool $verified Whether both password addresses were proved
     * @param ?int $collisionOwner Owner of the existing link at the loser's password address, if any
     * @param ?int $keptOwner Original owner of the surviving password, or no password
     */
    #[DataProvider('passwordAddressChoices')]
    public function testPasswordChoicesKeepMomentsAddressesAndDeletionLogs(
        ?PasswordFate $fate, bool $target, bool $source, bool $verified, ?int $collisionOwner, ?int $keptOwner,
    ): void {
        $moments = [self::SURVIVOR => 1700000000123, self::LOSER => 1710000000456];
        $passwords = [];
        foreach ([self::SURVIVOR => $target, self::LOSER => $source] as $userId => $present) {
            if ($present) {
                $password = Hilos::$db->identities->createPasswordIdentity($userId, 'merge-' . $userId . '@example.test', 'secret-' . $userId);
                if ($verified) {
                    $password->markVerified();
                }
                Database::sqlRun('UPDATE `hilos_identity` SET `password_changed_at` = ? WHERE `id` = ?', [$moments[$userId], $password->id]);
                $passwords[$userId] = $password->id;
            }
        }
        $linkId = $collisionOwner === null ? null : Hilos::$db->identities->createMagicLinkIdentity(
            $collisionOwner, 'merge-' . self::LOSER . '@example.test',
        )->id;
        // The fixture changed stored moments deliberately; the live owners start from a fresh context.
        $this->freshContext();
        $log = (string)tempnam(sys_get_temp_dir(), 'merge-password-log');
        Logger::setLogFile($log);
        try {
            $operation = $this->open(new AccountMergeChoice($fate, null, null, null));
            $this->finishExchange($operation);
            $written = (string)file_get_contents($log);
            $kept = Hilos::$db->identities->findPasswordByUser(self::SURVIVOR);
            self::assertSame($keptOwner === null ? null : $passwords[$keptOwner], $kept?->id);
            self::assertSame($keptOwner === null ? null : $moments[$keptOwner], $kept?->passwordChangedAt);
            $deleted = 0;
            foreach ($passwords as $ownerId => $id) {
                $row = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$id])->firstRow();
                $keeps = $ownerId === $keptOwner;
                $drops = !$keeps && (!$verified || ($collisionOwner !== null && $ownerId === self::LOSER));
                if ($drops) {
                    $deleted++;
                    self::assertNull($row);
                    self::assertStringContainsString('Account merge deleted an identity row instead of demoting it', $written);
                    self::assertStringContainsString('"reason":"' . ($verified ? 'magic_link_exists' : 'unverified') . '"', $written);
                    self::assertStringContainsString('"identityId":' . $id, $written);
                    self::assertStringContainsString('"identifier":"merge-' . $ownerId . '@example.test"', $written);
                    self::assertStringContainsString('"ownerUserId":' . $ownerId, $written);
                    self::assertStringContainsString('"fromUserId":' . self::LOSER, $written);
                    self::assertStringContainsString('"toUserId":' . self::SURVIVOR, $written);
                    if (!$verified) {
                        self::assertNull(Hilos::$db->identities->findAccountIdByEmail('merge-' . $ownerId . '@example.test'));
                        $replacementOwner = $ownerId === self::SURVIVOR ? 11 : 13;
                        $replacement = Hilos::$db->identities->createPasswordIdentity(
                            $replacementOwner, 'merge-' . $ownerId . '@example.test', 'replacement',
                        );
                        self::assertSame($replacementOwner, $replacement->userId);
                    }
                    continue;
                }
                self::assertNotNull($row);
                self::assertSame(self::SURVIVOR, (int)$row['user_id']);
                self::assertSame($keeps ? IdentityType::PASSWORD : IdentityType::MAGIC_LINK, $row['type']);
                self::assertSame($keeps ? $moments[$ownerId] : null,
                    $row['password_changed_at'] === null ? null : (int)$row['password_changed_at']);
                self::assertSame($verified, (bool)$row['verified']);
                if (!$keeps) {
                    self::assertNull($row['secret']);
                }
                self::assertSame(self::SURVIVOR, Hilos::$db->identities->findAccountIdByEmail($row['identifier']));
                if ($keptOwner !== null) {
                    self::assertTrue($kept->verifyPassword('secret-' . $keptOwner));
                }
            }
            self::assertSame($deleted, substr_count($written, 'Account merge deleted an identity row instead of demoting it'));
            if ($collisionOwner !== null) {
                $link = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$linkId])->firstRow();
                self::assertNotNull($link);
                self::assertSame($collisionOwner, (int)$link['user_id']);
                self::assertSame(IdentityType::MAGIC_LINK, $link['type']);
                self::assertSame('merge-' . self::LOSER . '@example.test', $link['identifier']);
                self::assertSame($linkId, Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $link['identifier'])?->id);
                self::assertSame($collisionOwner, Hilos::$db->identities->findAccountIdByEmail($link['identifier']));
            }
        } finally {
            Logger::resetLogFile();
            unlink($log);
        }
    }

    public function testProjectPreflightFollowsFrameworkRefusalsAndPrecedesTheOpening(): void
    {
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        $this->holder->refusePreflight = new ValidationException('The project refuses this pair.');
        $failure = $this->refused(ValidationException::class, fn () => $this->open(loser: 99));
        self::assertSame('No such user: 99', $failure->getMessage());
        self::assertFalse($this->holder->projectPreflightAsked);
        $failure = $this->refused(ValidationException::class, fn () => $this->open());
        self::assertSame('The project refuses this pair.', $failure->getMessage());
        self::assertTrue($this->holder->projectPreflightAsked);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation`')->rows());
        self::assertSame([], $this->frames());
    }

    public function testCompletedMergeRemainsInTheErasureCircleAndRefusalsFollowTheLiveChain(): void
    {
        $this->finishExchange($this->open());
        self::assertSame([self::LOSER, self::SURVIVOR], Hilos::$db->userMerges->erasureCircleOf(self::SURVIVOR)->toArray());
        $this->finishExchange($this->open(survivor: 11, loser: self::SURVIVOR));
        self::assertSame([self::LOSER, self::SURVIVOR, 11], Hilos::$db->userMerges->erasureCircleOf(11)->toArray());
        $failure = $this->refused(ValidationException::class, fn () => $this->open(survivor: 13));
        self::assertSame('Loser (#9) was already merged into Other (#11)', $failure->getMessage());
    }

    public function testExactRepeatPreservesThePairChoiceAndOperationWhileConflictsRefuse(): void
    {
        $operation = $this->open();
        $first = $this->frames();
        self::assertSame(['prepare_survivor', 'prepare_loser'], $this->keys($first));
        self::assertSame($operation->id, $this->open()->id);
        self::assertSame(array_map(static fn (SignalDTO $frame): int => $frame->data->data->assignmentId, $first),
            array_map(static fn (SignalDTO $frame): int => $frame->data->data->assignmentId, $this->frames()));
        foreach ([
            fn () => $this->open(survivor: 11),
            fn () => $this->open(choice: new AccountMergeChoice(PasswordFate::NONE, null, null, null)),
            fn () => $this->open(survivor: self::SURVIVOR, loser: 11),
            fn () => $this->open(survivor: 11, loser: self::SURVIVOR),
            fn () => $this->open(survivor: self::LOSER, loser: 11),
        ] as $conflict) {
            $failure = $this->refused(ValidationException::class, $conflict);
            self::assertStringContainsString('already being merged', $failure->getMessage());
        }
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string}> New-operation preflight failures */
    public static function preflights(): iterable
    {
        foreach (['same', 'missing', 'folded', 'erase-survivor', 'erase-loser', 'password'] as $case) {
            yield $case => [$case];
        }
    }

    /** @param string $case Current preflight refusal */
    #[DataProvider('preflights')]
    public function testNewOpeningUsesCurrentPreflightWithoutRecordingAMerge(string $case): void
    {
        if ($case === 'folded') {
            Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (9, 11, 1)');
        }
        if (str_starts_with($case, 'erase-')) {
            $id = $case === 'erase-survivor' ? self::SURVIVOR : self::LOSER;
            $this->asHolder(static fn () => Hilos::$db->accountOperations->actions->recordErasure(1, $id,
                AccountOperationCircle::fromArray([$id], $id)));
        }
        if ($case === 'password') {
            self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
            self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        }
        $this->refused(ValidationException::class, fn () => $this->open(
            survivor: $case === 'same' ? self::LOSER : self::SURVIVOR,
            loser: $case === 'missing' ? 99 : self::LOSER,
        ));
        self::assertSame([], Database::sql("SELECT `id` FROM `hilos_account_operation` WHERE `kind` = 'merge'")->rows());
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{bool}> Both orders of the personal frames */
    public static function orders(): iterable
    {
        yield 'survivor-first' => [false];
        yield 'loser-first' => [true];
    }

    /** @param bool $reverse Whether the loser prepares first */
    #[DataProvider('orders')]
    public function testBothSavedPreparationsPrecedeGoAndItsFiveFixedDuties(bool $reverse): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        if ($reverse) {
            $asks = array_reverse($asks);
        }
        $this->deliver($asks[0]);
        [$firstAnswer] = $this->frames();
        $this->deliver($firstAnswer);
        self::assertSame([], $this->frames());
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, AccountMergeStage::Go->value));
        $this->deliver($asks[1]);
        [$secondAnswer] = $this->frames();
        self::assertSame('preparing', $this->row($operation)['stage']);
        $this->deliver($secondAnswer);
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertSame(['give'], $this->keys($this->frames()));
        $duties = Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, AccountMergeStage::Go->value);
        self::assertSame(['give', 'receive', 'sessions', 'survivor_sessions', 'cleanup'],
            array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey, iterator_to_array($duties, false)));
        foreach ($duties as $assignment) {
            self::assertSame('{}', $assignment->payloadJson);
            $survivor = in_array($assignment->assignmentKey, ['receive', 'survivor_sessions'], true);
            self::assertSame($survivor ? self::SURVIVOR : self::LOSER, $assignment->userId);
            self::assertSame(in_array($assignment->assignmentKey, ['sessions', 'survivor_sessions'], true)
                ? HilosAgentType::HILOS_SESSIONS_LIBRARY : HilosAgentType::HILOS_USER . ':' . $assignment->userId, $assignment->owner->agentId());
        }
        $this->deliver($firstAnswer);
        $this->deliver($secondAnswer);
        self::assertSame([], $this->frames());
    }

    /** @param bool $reverse Whether the reserved loser answers first */
    #[DataProvider('orders')]
    public function testRefusedAndReservedWaitForBothThenReleaseBothOwners(bool $reverse): void
    {
        $operation = $this->open(choice: new AccountMergeChoice(null, null, true, false));
        $asks = $this->frames();
        if ($reverse) {
            $asks = array_reverse($asks);
        }
        $this->deliver($asks[0]);
        $this->deliver($this->frames()[0]);
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertSame([], $this->frames());
        $this->deliver($asks[1]);
        $this->deliver($this->frames()[0]);
        self::assertSame(AccountMergeRefusal::ProtectionChanged, AccountMergeCoordinator::refusalOf($operation));
        $this->finishCancellation($operation, $this->frames());
    }

    /** @param bool $reverse Whether the loser's lower-priority refusal arrives first */
    #[DataProvider('orders')]
    public function testRefusalPriorityDoesNotDependOnDeliveryOrder(bool $reverse): void
    {
        $operation = $this->open(choice: new AccountMergeChoice(null, SecondFactorFate::SURVIVOR, true, false));
        $asks = $this->frames();
        foreach ($reverse ? array_reverse($asks) : $asks as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        self::assertSame(AccountMergeRefusal::ProtectionChanged, AccountMergeCoordinator::refusalOf($operation));
        $this->finishCancellation($operation, $this->frames());
    }

    public function testTwoPasswordsCapturedAfterPreflightRequireAFateBeforeGo(): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        $this->freshContext();
        foreach ($asks as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        self::assertSame(AccountMergeRefusal::PasswordFateRequired, AccountMergeCoordinator::refusalOf($operation));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, AccountMergeStage::Go->value));
        $this->finishCancellation($operation, $this->frames());
    }

    public function testGoReentryRestoresAMissingFixedDutyWithoutRewritingTheOthers(): void
    {
        [$operation, $give] = $this->readyGo();
        $before = Database::sql('SELECT * FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? ORDER BY `id`',
            [$operation->id])->rows();
        $receive = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Receive->value);
        Database::sqlRun('DELETE FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$receive->id]);
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $asks = $this->frames();
        self::assertSame(['give'], $this->keys($asks));
        self::assertSame($give->data->data->assignmentId, $asks[0]->data->data->assignmentId);
        $restored = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value,
            AccountMergeDuty::Receive->value);
        self::assertNotNull($restored);
        self::assertNotSame($receive->id, $restored->id);
        self::assertSame('{}', $restored->payloadJson);
        self::assertSame(self::SURVIVOR, $restored->userId);
        self::assertSame(HilosAgentType::HILOS_USER . ':' . self::SURVIVOR, $restored->owner->agentId());
        self::assertSame(array_values(array_filter($before, static fn (array $row): bool => (int)$row['id'] !== $receive->id)),
            Database::sql('SELECT * FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `id` <> ? ORDER BY `id`',
                [$operation->id, $restored->id])->rows());
        self::assertSame('go', $this->row($operation)['stage']);
    }

    /** @return iterable<string, array{int}> Cancellation before zero, one or both saved preparations */
    public static function preparedCounts(): iterable
    {
        yield 'neither' => [0];
        yield 'one' => [1];
        yield 'both' => [2];
    }

    /** @param int $count Preparations saved before the explicit cancel */
    #[DataProvider('preparedCounts')]
    public function testExplicitCancelRejectsDelayedPreparingFrames(int $count): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        $answers = [];
        foreach (array_slice($asks, 0, $count) as $ask) {
            $this->deliver($ask);
            array_push($answers, ...$this->frames());
        }
        self::assertSame(AccountMergeCancellationState::WaitingOwners, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        $releases = $this->frames();
        foreach ($answers as $answer) {
            $this->deliver($answer);
        }
        foreach (array_slice($asks, $count) as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        self::assertSame([], $this->frames());
        $this->finishCancellation($operation, $releases);
    }

    public function testGoWithoutFactCanCancelAndHeldGiveCannotTransferAfterCancellation(): void
    {
        [$operation, $give] = $this->readyGo();
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        self::assertSame(AccountMergeCancellationState::WaitingOwners, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        $releases = $this->frames();
        $this->deliver($give);
        [$refusal] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Refused, $refusal->data->data->answer);
        $this->deliver($refusal);
        $this->finishCancellation($operation, $releases);
        $this->deliver($give);
        $this->deliver($this->frames()[0]);
        self::assertSame([], $this->frames());
        self::assertSame((string)self::LOSER, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
    }

    public function testGiveHookWaitsInPrimaryAndCancellationRefusesTheDelayedAsk(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $agent = $this->personAgent(self::LOSER);
        self::assertInstanceOf(MergeCoordinatorUser::class, $agent);
        $calls = [];
        $rolledBack = false;
        $agent->beforeGive = static function (AccountMergePart $part) use (&$calls, &$rolledBack): void {
            $calls[] = $part;
            self::assertSame((string)self::LOSER, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
            Database::onRollback(static function () use (&$rolledBack): void {
                $rolledBack = true;
            }, Database::getDatabase(DatabaseNames::PRIMARY)->connectionIndex);

            throw new AccountMergeGiveWaitsException();
        };
        $before = $this->operationFacts();
        $this->deliver($give);
        $frames = $this->frames();
        self::assertCount(1, $frames);
        [$wait] = $frames;
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $wait->data->data);
        self::assertSame(AccountOperationAnswerKind::Waits, $wait->data->data->answer);
        self::assertEquals([new AccountMergePart($operation->id, self::SURVIVOR, self::LOSER)], $calls);
        self::assertTrue($rolledBack);
        self::assertFalse(Database::hasOpenTransactions());
        $this->deliver($wait);
        self::assertSame([], $this->frames());
        self::assertSame($before, $this->operationFacts());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId));
        self::assertSame((string)self::LOSER, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertFalse(Hilos::$db->users[self::LOSER]->block);

        self::assertSame(AccountMergeCancellationState::WaitingOwners, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        $this->finishCancellation($operation, $this->frames());
        $afterCancellation = $this->operationFacts();
        $this->deliver($give);
        $frames = $this->frames();
        self::assertCount(1, $frames);
        [$refusal] = $frames;
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $refusal->data->data);
        self::assertSame(AccountOperationAnswerKind::Refused, $refusal->data->data->answer);
        $this->deliver($refusal);
        self::assertSame([], $this->frames());
        self::assertCount(1, $calls);
        self::assertSame($afterCancellation, $this->operationFacts());
        self::assertSame((string)self::LOSER, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertFalse(Hilos::$db->users[self::LOSER]->block);
    }

    public function testCommittedGiveMakesCancelTooLateAndExactRepeatContinuesBeforePreflight(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover, $heldAnswer] = $this->frames();
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $handover->data->data);
        self::assertSame(AccountOperationAnswerKind::Done, $heldAnswer->data->data->answer);
        $result = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId);
        self::assertNotNull($result);
        self::assertSame(AccountMergeCancellationState::TooLate, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        self::assertSame(['give', 'receive'], $this->keys($this->frames()));
        self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, AccountMergeStage::Cancelling->value));
        self::assertSame($operation->id, $this->open()->id);
        self::assertSame(['give', 'receive'], $this->keys($this->frames()));
        $agent = $this->personAgent(self::LOSER);
        self::assertInstanceOf(MergeCoordinatorUser::class, $agent);
        $agent->beforeGive = static function (): void {
            self::fail('Saved Give must bypass the project hook.');
        };
        $this->deliver($give);
        [$replayedHandover, $replayedAnswer] = $this->frames();
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $replayedHandover->data->data);
        self::assertSame(AccountOperationAnswerKind::Done, $replayedAnswer->data->data->answer);
        $replayedResult = $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent): AccountOperationResult =>
            $agent->giveForMerge($give->data->data->assignmentId));
        self::assertSame($result->toArray(), $replayedResult->toArray());
        self::assertSame($result->id, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->id);
        self::assertSame((string)self::SURVIVOR, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['finished_at']);
    }

    public function testForeignReleaseResultCannotCloseCancellationOnBeginOrAnswerInAColdContext(): void
    {
        $operation = $this->open();
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($operation));
        $answers = [];
        foreach ($this->frames() as $release) {
            $this->deliver($release);
            array_push($answers, ...$this->frames());
        }
        self::assertCount(2, $answers);
        Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
            [HilosAgentType::HILOS_USER . ':11', $answers[0]->data->data->assignmentId]);
        $this->freshContext();
        $this->refused(InvalidFormatException::class, fn () => $this->asHolder(fn () => $this->holder->driveMerge($operation)));
        $this->refused(InvalidFormatException::class, fn () => $this->deliver($answers[1]));
        self::assertSame('cancelling', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['outcome']);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string}> Invalid persisted proof coordinates */
    public static function corruptions(): iterable
    {
        yield 'foreign-owner' => ['owner_agent_id'];
        yield 'malformed-payload' => ['payload_json'];
    }

    /** @param string $column Corrupted proof field */
    #[DataProvider('corruptions')]
    public function testInvalidPreparationProofFailsClosedWithoutBusinessCancellation(string $column): void
    {
        $operation = $this->open();
        $answers = [];
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            array_push($answers, ...$this->frames());
        }
        Database::sqlRun('UPDATE `hilos_account_operation_result` SET `' . $column . '` = ? WHERE `assignment_id` = ?',
            [$column === 'payload_json' ? '{}' : HilosAgentType::HILOS_USER . ':11', $answers[0]->data->data->assignmentId]);
        $this->freshContext();
        $this->refused(InvalidFormatException::class, fn () => $this->deliver($answers[1]));
        $this->refused(InvalidFormatException::class, fn () => $this->asHolder(fn () => $this->holder->driveMerge($operation)));
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string, string}> Fail the stage CAS or a fixed duty after the first insert */
    public static function goFaults(): iterable
    {
        yield 'stage' => ['hilos_account_operation', "`stage` <> 'go'"];
        yield 'second-duty' => ['hilos_account_operation_assignment', "`assignment_key` <> 'receive'"];
    }

    /**
     * @param string $table Table whose real write is refused
     * @param string $check Narrow storage failure condition
     */
    #[DataProvider('goFaults')]
    public function testGoAndFixedDutiesRollbackTogetherWithoutAnnouncementsAndCanRetry(string $table, string $check): void
    {
        $operation = $this->open();
        $answers = [];
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            array_push($answers, ...$this->frames());
        }
        $object = $operation->getObject();
        $before = $this->row($operation);
        $reaction = new MergeCoordinatorReaction();
        SourceChangeBus::subscribe($reaction);
        Database::sqlRun('ALTER TABLE `' . $table . '` ADD CONSTRAINT `reject_merge_go` CHECK (' . $check . ')');
        try {
            $this->refused(DatabaseException::class, fn () => $this->deliver($answers[1]));
            self::assertSame($before, $this->row($operation));
            self::assertSame($object, $operation->getObject());
            self::assertSame('preparing', $operation->stage);
            self::assertCount(0, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, AccountMergeStage::Go->value));
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->frames());
        } finally {
            Database::sqlRun('ALTER TABLE `' . $table . '` DROP CONSTRAINT `reject_merge_go`');
        }
        $this->deliver($answers[1]);
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertSame(['give'], $this->keys($this->frames()));
    }

    public function testTransportSourceMustMatchEvenWhenBothPreparationResultsAlreadyExist(): void
    {
        $operation = $this->open();
        $asks = $this->frames();
        $missing = new AccountOperationAnswerSignalData($operation->id, $asks[0]->data->data->stage,
            $asks[0]->data->data->assignmentId, AccountOperationAnswerKind::Done);
        $this->asHolder(fn () => $this->holder->onSignalAgent(new AgentSignalData($missing),
            SignalSource::describe(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, (string)self::SURVIVOR)),
            HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER));
        self::assertSame([], $this->frames());
        $answers = [];
        foreach ($asks as $ask) {
            $this->deliver($ask);
            array_push($answers, ...$this->frames());
        }
        $answer = $answers[1];
        $this->deliver(new SignalDTO(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, '11'),
            $answer->signalType, $answer->signalName, $answer->data));
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertSame([], $this->frames());
        $this->deliver($answer);
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertSame(['give'], $this->keys($this->frames()));
    }

    /** @return iterable<string, array{bool}> Generic receive on either side of the source handover */
    public static function receiveOrders(): iterable
    {
        yield 'generic before handover' => [true];
        yield 'generic after handover' => [false];
    }

    /** @param bool $genericFirst Whether the cold survivor gets its generic ask first */
    #[DataProvider('receiveOrders')]
    public function testSerializedHandoverAndGenericReceiveContinueThroughTheHolderToEnd(bool $genericFirst): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $handover->data->data);
        self::assertSame('agent/hilos_user#9', SignalSource::describe($handover->signalSource));
        self::assertNotSame($give->data->data->assignmentId, $handover->data->data->assignmentId);
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId));
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        $this->deliver($giveAnswer);
        [$receive] = $this->frames();
        self::assertSame(['receive'], $this->keys([$receive]));
        $this->freshContext();
        if ($genericFirst) {
            $this->deliver($receive);
            [$wait] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Waits, $wait->data->data->answer);
            $this->deliver($wait);
            self::assertSame([], $this->frames());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($receive->data->data->assignmentId));
        }
        $this->deliver($handover);
        [$receiptAnswer] = $this->frames();
        self::assertSame($receive->data->data->assignmentId, $receiptAnswer->data->data->assignmentId);
        self::assertSame(AccountOperationAnswerKind::Done, $receiptAnswer->data->data->answer);
        $receipt = Hilos::$db->accountOperationResults->byAssignment($receive->data->data->assignmentId);
        self::assertNotNull($receipt);
        self::assertSame('hilos_user:7', $receipt->ownerAgentId);
        if (!$genericFirst) {
            $this->deliver($receive);
            [$replay] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Done, $replay->data->data->answer);
            self::assertSame($receipt->id, Hilos::$db->accountOperationResults->byAssignment($receive->data->data->assignmentId)->id);
        }
        $this->deliver($receiptAnswer);
        $this->finishExchange($operation);
    }

    /** @return iterable<string, array{string}> Lost notifications or handover before an explicit drive */
    public static function losses(): iterable
    {
        foreach (['give-ask', 'give-answer', 'handover', 'receipt-answer'] as $loss) {
            yield $loss => [$loss];
        }
    }

    /** @param string $loss Frame deliberately discarded from the real queue */
    #[DataProvider('losses')]
    public function testExplicitDriveRecoversLostFramesWithoutSecondTransfer(string $loss): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $saved = null;
        if ($loss !== 'give-ask') {
            $this->deliver($give);
            [$handover, $giveAnswer] = $this->frames();
            $saved = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
            if ($loss !== 'handover') {
                if ($loss === 'receipt-answer') {
                    $this->deliver($giveAnswer);
                    $this->frames();
                }
                $this->freshContext();
                $this->deliver($handover);
                [$receiptAnswer] = $this->frames();
                if ($loss === 'give-answer') {
                    $this->deliver($receiptAnswer);
                    $this->frames();
                }
            }
            if ($loss === 'handover') {
                $this->deliver($giveAnswer);
                $this->frames(); // Hold generic receive; drive must also replay the saved give only once.
            }
        }
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $this->finishExchange($operation);
        $result = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId);
        if ($saved !== null) {
            self::assertSame($saved, $result->toArray());
        }
        self::assertSame((string)self::SURVIVOR, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
    }

    public function testDuplicateHandoverAndAnswersDoNotLoopOrRepeatOwnProtection(): void
    {
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $operation = $this->open(new AccountMergeChoice(PasswordFate::NONE, null, null, null));
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        $this->freshContext();
        $this->deliver($handover);
        [$receiptAnswer] = $this->frames();
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'new-password@example.test');
        $this->freshContext();
        $before = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        $receipt = Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray();
        $this->deliver($handover);
        [$duplicate] = $this->frames();
        self::assertSame($receiptAnswer->data->toArray(), $duplicate->data->toArray());
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        self::assertSame($receipt, Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray());
        $this->deliver($give);
        $giveReplay = $this->frames();
        self::assertCount(1, $giveReplay);
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $giveReplay[0]->data->data);
        self::assertSame(AccountOperationAnswerKind::Done, $giveReplay[0]->data->data->answer);
        $this->deliver($giveAnswer);
        $this->deliver($giveAnswer);
        self::assertNotContains('give', $this->keys($this->frames()));
        $this->deliver($receiptAnswer);
        $this->finishExchange($operation);
        $this->deliver($receiptAnswer);
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string, bool}> Forged coordinates before and after a durable receipt */
    public static function forgedHandovers(): iterable
    {
        foreach (['pair', 'give', 'receive', 'operation', 'manifest', 'sender', 'address'] as $defect) {
            yield "$defect before receipt" => [$defect, false];
            yield "$defect saved receipt" => [$defect, true];
        }
    }

    /**
     * @param string $defect Handled frame defect
     * @param bool $received Whether a valid receipt already exists
     */
    #[DataProvider('forgedHandovers')]
    public function testForgedHandoverCannotRecordOrDiscloseAReceipt(string $defect, bool $received): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover] = $this->frames();
        $receipt = null;
        if ($received) {
            $this->deliver($handover);
            $this->frames();
            $receipt = Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray();
        }
        $fields = $handover->data->data->toArray();
        if ($defect === 'pair') {
            $fields['ownerIndex'] = '11';
            $fields['handover']['copy']['survivorUserId'] = 11;
            $fields['handover']['copy']['identities'][0]['userId'] = 11;
        } elseif ($defect === 'give') {
            $fields['handover']['copy']['assignmentId'] = 999;
        } elseif ($defect === 'receive') {
            $fields['assignmentId'] = Hilos::$db->accountOperationAssignments->byIdentity($operation->id,
                AccountMergeStage::Go->value, AccountMergeDuty::Cleanup->value)->id;
        } elseif ($defect === 'operation') {
            $fields['operationId'] = 999;
            $fields['handover']['copy']['operationId'] = 999;
        } elseif ($defect === 'manifest') {
            $fields['handover']['admission']['identities'][0]['revision']++;
        }
        $forged = new SignalDTO($defect === 'sender' ? new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, '11')
            : $handover->signalSource, $handover->signalType, $handover->signalName,
            new AgentSignalData(AccountMergeHandoverSignalData::fromArray($fields)));
        $this->freshContext();
        if ($defect === 'address') {
            $this->refused(AgentException::class, fn () => $this->asPerson(11, static fn (AbstractUserAgent $agent) =>
                $agent->onSignalAgent($forged->data, SignalSource::describe($forged->signalSource), $forged->signalName->getName())));
        } elseif ($defect === 'sender') {
            $this->refused(AgentException::class, fn () => $this->deliver($forged));
        } else {
            $this->deliver($forged);
            [$answer] = $this->frames();
            self::assertNotSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
            $this->deliver($answer);
        }
        self::assertSame([], $this->frames());
        self::assertSame($receipt, Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)?->toArray());
        self::assertNull($this->row($operation)['finished_at']);
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[
            $handover->data->data->handover->copy->identities[0]->id]));
    }

    public function testNewerUnappliedCurrentRowAnswersWaitsAndOriginalHandoverCannotOverwriteIt(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover] = $this->frames();
        $id = $handover->data->data->handover->copy->identities[0]->id;
        $this->asPerson(self::SURVIVOR, static function () use ($id): void {
            $object = Hilos::$db->identities[$id]->getObject();
            $object->providerName = 'current provider name';
            $object->sync();
        });
        $current = null;
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncUpdatedSignalData && $frame->data->collectionKey === HilosDbContext::identities) {
                $current = $frame->data;
            }
        }
        self::assertNotNull($current);
        $this->freshContext();
        $this->deliver($handover);
        [$wait] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Waits, $wait->data->data->answer);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId));
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[$id]));
        $this->deliver($wait);
        self::assertSame([], $this->frames());
        self::assertTrue(DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData($current->collectionKey, $current->idString,
            $current->admission->snapshot, writerAgentId: $current->writerAgentId, admission: $current->admission), false)->accepted);
        $this->deliver($handover);
        self::assertSame('current provider name', Hilos::$db->identities[$id]->providerName);
        $this->finishExchange($operation);
    }

    public function testFailedReceiptStorageLeavesNoReceiptOrHydrationAndCanBeRetried(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover] = $this->frames();
        $this->freshContext();
        Database::sqlRun("CREATE TRIGGER `handover_receipt_failure` BEFORE INSERT ON `hilos_account_operation_result`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handover receipt storage failure'");
        try {
            $this->deliver($handover);
            [$answer] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Failed, $answer->data->data->answer);
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId));
            self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[
                $handover->data->data->handover->copy->identities[0]->id]));
            $this->deliver($answer);
            self::assertSame([], $this->frames());
            self::assertNull($this->row($operation)['finished_at']);
        } finally {
            Database::sqlRun('DROP TRIGGER `handover_receipt_failure`');
        }
        $this->deliver($handover);
        $this->finishExchange($operation);
    }

    public function testLateHandoverAfterCleanupAndEndReturnsOnlySavedReceiptWithoutMemoryHydration(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        [$handover] = $this->frames();
        $this->deliver($handover);
        $this->finishExchange($operation);
        $receipt = Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray();
        $newOperation = $this->open(loser: 11);
        $this->frames();
        $this->freshContext();
        $this->deliver($handover);
        [$replay] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Done, $replay->data->data->answer);
        self::assertSame($receipt, Hilos::$db->accountOperationResults->byAssignment($handover->data->data->assignmentId)->toArray());
        self::assertFalse(isset(Hilos::$db->mountedObjectCollection(HilosDbContext::identities)[
            $handover->data->data->handover->copy->identities[0]->id]));
        $this->deliver($replay);
        self::assertSame([], $this->frames());
        self::assertSame('preparing', $this->row($newOperation)['stage']);
        self::assertNull($this->row($newOperation)['finished_at']);
        $this->deliver($give);
        [$lateGiveAnswer] = $this->frames();
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $lateGiveAnswer->data->data);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
    }

    public function testMissingSourceCopyIsNeitherRebuiltNorMistakenForAReceipt(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $this->frames();
        $saved = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy`');
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $asks = $this->frames();
        self::assertSame(['give', 'receive'], $this->keys($asks));
        $this->deliver($asks[0]);
        $answers = $this->frames();
        self::assertCount(1, $answers);
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answers[0]->data->data);
        self::assertSame(AccountOperationAnswerKind::Done, $answers[0]->data->data->answer);
        self::assertSame($saved, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
        $this->deliver($asks[1]);
        [$wait] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Waits, $wait->data->data->answer);
        $this->deliver($wait);
        self::assertSame([], $this->frames());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($asks[1]->data->data->assignmentId));
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
    }

    public function testSendFailureAfterGiveCommitStillAnswersDoneAndDriveOnlyReplaysTheCopy(): void
    {
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        $agent = $this->personAgent(self::LOSER);
        self::assertInstanceOf(MergeCoordinatorUser::class, $agent);
        $agent->failHandover = true;
        $this->deliver($give);
        [$answer] = $this->frames();
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer->data->data);
        self::assertSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
        $saved = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        self::assertSame((string)self::SURVIVOR, Database::sql('SELECT `user_id` FROM `hilos_identity`')->row()['user_id']);
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        $agent->failHandover = false;
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $this->finishExchange($operation);
        self::assertSame($saved, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
    }

    public function testCoordinatorRequiresCanonicalHolderAndAnIndependentTransactionBoundary(): void
    {
        $operation = $this->open();
        $this->frames();
        $this->refused(InvalidFormatException::class, static fn () => AccountMergeCoordinator::begin($operation));
        $this->refused(InvalidFormatException::class, fn () => $this->asPerson(self::LOSER,
            static fn () => AccountMergeCoordinator::cancel($operation)));
        $this->refused(LogicException::class, fn () => $this->asHolder(static fn () => Database::transaction(
            DatabaseNames::PRIMARY, static fn () => AccountMergeCoordinator::begin($operation),
        )));
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{?AccountMergeDuty}> Last outstanding owner proof after physical cleanup */
    public static function heldProofs(): iterable
    {
        yield 'project' => [null];
        yield 'loser-sessions-inline' => [AccountMergeDuty::Sessions];
        yield 'survivor-sessions-inline' => [AccountMergeDuty::SurvivorSessions];
    }

    /** @param ?AccountMergeDuty $heldDuty Inline proof to withhold until the project has really finished */
    #[DataProvider('heldProofs')]
    public function testOriginalProjectExchangeAndEveryHeldHolderProofKeepEndOpenAfterCleanup(?AccountMergeDuty $heldDuty): void
    {
        $this->activateProject();
        $this->holder->heldDuty = $heldDuty;
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        Database::sqlRun('INSERT INTO `hilos_access_log` (`user_id`, `event`, `occurred_at`) VALUES (?, ?, ?)',
            [self::LOSER, AccessLogEvent::SIGN_IN->value, 1790416800000]);
        [$operation, $give] = $this->readyGo();
        [$initial] = AccountOperationExpectedSet::of($operation->id)->expected();
        $immutable = $initial->toArray();
        self::assertSame('preparing', $initial->stage);
        self::assertSame(MergeCoordinatorProjectOwner::AGENT_TYPE, $initial->ownerType);
        self::assertNull($initial->ownerIndex);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($initial->id));

        $this->deliver($give);
        $held = $this->drainExcept([$initial->assignmentKey, AccountMergeDuty::Cleanup->value]);
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame([7, 9, 9], $this->noteOwners());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($initial->id));
        self::assertNull($this->row($operation)['finished_at']);
        $projects = array_values(array_filter($held, fn (SignalDTO $frame): bool => $this->keys([$frame]) === [$initial->assignmentKey]));
        $cleanups = array_values(array_filter($held, fn (SignalDTO $frame): bool => $this->keys([$frame]) === ['cleanup']));
        self::assertNotEmpty($projects);
        self::assertNotEmpty($cleanups);
        foreach ($projects as $project) {
            self::assertSame($initial->id, $project->data->data->assignmentId);
            self::assertSame('preparing', $project->data->data->stage);
        }
        $this->deliver($cleanups[0]);
        $this->drainExcept([$initial->assignmentKey]);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame((string)($heldDuty === AccountMergeDuty::Sessions ? self::LOSER : self::SURVIVOR),
            Database::sql('SELECT `user_id` FROM `hilos_access_log`')->row()['user_id']);
        foreach ([AccountMergeDuty::Give, AccountMergeDuty::Receive, AccountMergeDuty::Sessions,
            AccountMergeDuty::SurvivorSessions, AccountMergeDuty::Cleanup] as $duty) {
            $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Go->value, $duty->value);
            self::assertNotNull($assignment);
            self::assertSame($duty === $heldDuty, Hilos::$db->accountOperationResults->byAssignment($assignment->id) === null);
        }
        self::assertNull($this->row($operation)['finished_at']);

        $this->deliver($projects[0]);
        [$answer] = $this->frames();
        self::assertSame('preparing', $answer->data->data->stage);
        self::assertSame('go', $this->row($operation)['stage']);
        self::assertSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
        $result = Hilos::$db->accountOperationResults->byAssignment($initial->id);
        self::assertNotNull($result);
        self::assertSame($initial->id, $result->assignmentId);
        self::assertSame($initial->owner->agentId(), $result->ownerAgentId);
        self::assertSame('{"rows":{"notes":2}}', $result->payloadJson);
        self::assertSame($immutable, Hilos::$db->accountOperationAssignments[$initial->id]->toArray());
        self::assertSame([7, 7, 7], $this->noteOwners());
        self::assertNull($this->row($operation)['finished_at']);
        $this->deliver($answer);
        if ($heldDuty !== null) {
            self::assertNull($this->row($operation)['finished_at']);
            self::assertSame([], $this->frames());
            $this->holder->heldDuty = null;
            $this->asHolder(fn () => $this->holder->driveMerge($operation));
        }
        $this->finishExchange($operation);
        self::assertSame((string)self::SURVIVOR, Database::sql('SELECT `user_id` FROM `hilos_access_log`')->row()['user_id']);
    }

    /** @return iterable<string, array{bool, bool}> Lost asks versus lost answers around the same receipt */
    public static function projectCleanupLosses(): iterable
    {
        yield 'both asks lost' => [false, false];
        yield 'project answer and cleanup ask lost' => [true, false];
        yield 'project ask and cleanup answer lost' => [false, true];
        yield 'both answers lost' => [true, true];
    }

    /**
     * @param bool $projectRan Whether the original project ask committed before its answer was lost
     * @param bool $cleanupRan Whether cleanup committed before its answer was lost
     */
    #[DataProvider('projectCleanupLosses')]
    public function testDriveRecoversCrossedProjectAndCleanupLossesWithoutRepeatingSavedWork(bool $projectRan, bool $cleanupRan): void
    {
        [$operation, $give, $project, $cleanup, $giveAnswer] = $this->projectCleanupWindow();
        $original = Hilos::$db->accountOperationAssignments[$project->data->data->assignmentId]->toArray();
        $giveResult = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        foreach ([$projectRan ? $project : null, $cleanupRan ? $cleanup : null] as $ask) {
            if ($ask !== null) {
                $this->deliver($ask);
                [$lost] = $this->frames();
                self::assertSame(AccountOperationAnswerKind::Done, $lost->data->data->answer);
            }
        }
        $projectResult = Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)?->toArray();
        $cleanupResult = Hilos::$db->accountOperationResults->byAssignment($cleanup->data->data->assignmentId)?->toArray();
        self::assertSame($projectRan ? [7, 7, 7] : [7, 9, 9], $this->noteOwners());
        self::assertCount($cleanupRan ? 0 : 1, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertNull($this->row($operation)['finished_at']);

        $missing = array_merge($projectRan ? [] : [$original['assignmentKey']], $cleanupRan ? [] : ['cleanup']);
        $this->deliver($giveAnswer); // An unrelated saved answer cannot stand in for either missing proof.
        self::assertSame($missing, $this->keys($this->frames()));
        self::assertSame(!$projectRan, Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId) === null);
        self::assertSame(!$cleanupRan, Hilos::$db->accountOperationResults->byAssignment($cleanup->data->data->assignmentId) === null);
        if ($missing !== []) {
            self::assertNull($this->row($operation)['finished_at']);
        }

        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $recovered = $this->frames();
        self::assertSame($missing, $this->keys($recovered));
        foreach ($recovered as $ask) {
            self::assertSame($operation->id, $ask->data->data->operationId);
            self::assertContains($ask->data->data->assignmentId, [$project->data->data->assignmentId, $cleanup->data->data->assignmentId]);
            $this->deliver($ask);
        }
        $this->finishExchange($operation);
        self::assertSame($original, Hilos::$db->accountOperationAssignments[$project->data->data->assignmentId]->toArray());
        self::assertSame($giveResult, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
        if ($projectResult !== null) {
            self::assertSame($projectResult, Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->toArray());
        }
        if ($cleanupResult !== null) {
            self::assertSame($cleanupResult, Hilos::$db->accountOperationResults->byAssignment($cleanup->data->data->assignmentId)->toArray());
        }
        self::assertSame('{"rows":{"notes":2}}', Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->payloadJson);
        self::assertSame([7, 7, 7], $this->noteOwners());
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
    }

    /** Carried answers remain finite while receive waits, the holder runs inline and cleanup stays queued. */
    public function testDuplicateCarriedAnswersNeverReplayGiveOrCompletedInlineWorkAroundQueuedCleanup(): void
    {
        $this->activateProject();
        $this->holder->heldDuty = AccountMergeDuty::Sessions;
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        $operation = $this->open();
        $prepared = [];
        foreach ($this->frames() as $prepare) {
            $this->deliver($prepare);
            [$answer] = $this->frames();
            $prepared[] = $answer;
            $this->deliver($answer);
        }
        [$give] = $this->frames();
        $this->deliver($give);
        [$lostHandover, $giveAnswer] = $this->frames();
        $this->deliver($giveAnswer);
        [$receive, $project] = $this->frames();
        $this->deliver($project);
        [$carried] = $this->frames();
        self::assertSame('preparing', $carried->data->data->stage);
        $projectResult = Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->toArray();
        $this->deliver($carried);
        $this->deliver($carried);
        $facts = $this->operationFacts();
        foreach ($prepared as $oldPersonal) {
            $this->deliver($oldPersonal);
        }
        self::assertSame([], $this->frames());
        self::assertSame($facts, $this->operationFacts());
        $this->freshContext();
        $this->deliver($receive);
        [$wait] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Waits, $wait->data->data->answer);
        $this->deliver($wait);
        self::assertSame([], $this->frames());

        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $reentry = $this->frames();
        self::assertSame(['give', 'receive'], $this->keys($reentry));
        $this->deliver($reentry[0]);
        [$handover, $replayedGive] = $this->frames();
        self::assertSame($lostHandover->data->toArray(), $handover->data->toArray());
        $this->deliver($replayedGive);
        $this->deliver($replayedGive);
        $queued = $this->frames();
        self::assertSame(['receive', 'receive'], $this->keys($queued));
        foreach ([$reentry[1], ...$queued] as $ask) {
            $this->deliver($ask);
            [$waiting] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Waits, $waiting->data->data->answer);
            $this->deliver($waiting);
        }
        self::assertSame([], $this->frames());
        $this->deliver($handover);
        [$receipt] = $this->frames();
        $this->deliver($receipt);
        $this->deliver($carried);
        $this->deliver($carried);
        foreach ($prepared as $oldPersonal) {
            $this->deliver($oldPersonal);
        }
        [$cleanup] = $this->frames();
        self::assertSame(['cleanup'], $this->keys([$cleanup]));
        $this->deliver($cleanup);
        [$cleanupAnswer] = $this->frames();
        $this->deliver($cleanupAnswer);
        self::assertSame([], $this->frames());
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());

        $facts = $this->operationFacts();
        $reaction = new MergeCoordinatorReaction();
        SourceChangeBus::subscribe($reaction);
        foreach ([$carried, $receipt, $carried, $project, $cleanup] as $frame) {
            $this->deliver($frame);
        }
        $duplicates = $this->frames();
        self::assertSame([$project->data->data->assignmentId, $cleanup->data->data->assignmentId],
            array_map(static fn (SignalDTO $frame): int => $frame->data->data->assignmentId, $duplicates));
        foreach ($duplicates as $answer) {
            $this->deliver($answer);
        }
        self::assertSame([], $this->frames());
        self::assertSame($facts, $this->operationFacts());
        self::assertSame([], $reaction->seen);
        self::assertSame($projectResult, Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->toArray());
        $this->holder->heldDuty = null;
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $this->finishExchange($operation);
    }

    /** A terminal project's original frames cannot borrow the next merge's stage, rows or protection. */
    public function testLateOriginalProjectAskAndAnswerStayWithTheirOperationAfterEndAndDuringANewMerge(): void
    {
        [$operation, , $project, $cleanup] = $this->projectCleanupWindow();
        $this->deliver($project);
        [$carried] = $this->frames();
        $this->deliver($cleanup);
        $this->finishExchange($operation);
        $closed = $this->operationFacts();
        $this->deliver($project);
        [$late] = $this->frames();
        self::assertSame($operation->id, $late->data->data->operationId);
        self::assertContains($late->data->data->answer, [AccountOperationAnswerKind::Done, AccountOperationAnswerKind::Refused]);
        $this->deliver($late);
        $this->deliver($carried);
        self::assertSame([], $this->frames());
        self::assertSame($closed, $this->operationFacts());

        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (11)');
        self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'current-password@example.test');
        self::seedIdentity(11, IdentityType::MAGIC_LINK, 'next-loser@example.test');
        $next = $this->open(loser: 11);
        foreach ($this->frames() as $prepare) {
            $this->deliver($prepare);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        $this->deliver($give);
        [$expectation] = AccountOperationExpectedSet::of($next->id)->expected();
        $pending = $this->drainExcept([$expectation->assignmentKey, AccountMergeDuty::Cleanup->value]);
        self::assertNotEmpty($pending);
        self::assertNotNull(AccountMergeReceiveResult::forOperation($next));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($expectation->id));
        $facts = $this->operationFacts();
        $identities = Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows();
        $passwordId = (int)Database::sql('SELECT `id` FROM `hilos_identity` WHERE `type` = ?', [IdentityType::PASSWORD])->row()['id'];
        $password = Hilos::$db->identities[$passwordId];
        $memory = $password->toArray();
        $object = $password->getObject();
        $this->deliver($project);
        [$replay] = $this->frames();
        self::assertSame($operation->id, $replay->data->data->operationId);
        self::assertSame($project->data->data->assignmentId, $replay->data->data->assignmentId);
        $this->deliver($replay);
        $this->deliver($carried);
        self::assertSame([], $this->frames());
        self::assertSame($facts, $this->operationFacts());
        self::assertSame($identities, Database::sql('SELECT * FROM `hilos_identity` ORDER BY `id`')->rows());
        self::assertSame($memory, $password->toArray());
        self::assertSame($object, $password->getObject());
        foreach ($pending as $ask) {
            $this->deliver($ask);
        }
        $this->finishExchange($next);
        self::assertSame([7, 7, 7, 7], $this->noteOwners());
    }

    /** @return iterable<string, array{bool}> Result insert failures while the other ask remains held */
    public static function projectCleanupStorageFaults(): iterable
    {
        yield 'project result rolls back notes' => [true];
        yield 'cleanup result rolls back copy deletion' => [false];
    }

    /** @param bool $projectFault Which real participant fails to save its result */
    #[DataProvider('projectCleanupStorageFaults')]
    public function testHeldProjectAndCleanupSurviveResultStorageFailureWithoutHalfWorkOrRefusal(bool $projectFault): void
    {
        [$operation, $give, $project, $cleanup] = $this->projectCleanupWindow();
        $ask = $projectFault ? $project : $cleanup;
        $before = $this->operationFacts();
        $reaction = new MergeCoordinatorReaction();
        SourceChangeBus::subscribe($reaction);
        Database::sqlRun('ALTER TABLE `hilos_account_operation_result` ADD CONSTRAINT `reject_crossed_result` CHECK (`assignment_id` <> '
            . $ask->data->data->assignmentId . ')');
        try {
            $this->deliver($ask);
            [$failed] = $this->frames();
            self::assertSame(AccountOperationAnswerKind::Failed, $failed->data->data->answer);
            self::assertSame($before, $this->operationFacts());
            self::assertFalse(Database::hasOpenTransactions());
            self::assertSame([], $reaction->seen);
            $this->deliver($failed);
            self::assertSame([], $this->frames());
            self::assertSame($before, $this->operationFacts());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation_result` DROP CONSTRAINT `reject_crossed_result`');
        }
        $giveResult = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $asks = $this->frames();
        self::assertSame([$project->data->data->assignmentId, $cleanup->data->data->assignmentId],
            array_map(static fn (SignalDTO $frame): int => $frame->data->data->assignmentId, $asks));
        foreach ($asks as $recovered) {
            $this->deliver($recovered);
        }
        $this->finishExchange($operation);
        self::assertSame($giveResult, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
        self::assertSame('{"rows":{"notes":2}}', Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->payloadJson);
    }

    /** A lost cleanup answer and a failed end transition leave every committed owner proof intact. */
    public function testEndStorageFailureAfterLostCleanupAnswerReentersWithoutResendingCompletedDuties(): void
    {
        [$operation, , $project, $cleanup] = $this->projectCleanupWindow();
        $this->deliver($cleanup);
        $this->frames(); // Lose the answer after the copy deletion and cleanup proof commit.
        $this->deliver($project);
        [$carried] = $this->frames();
        $before = $this->operationFacts();
        $reaction = new MergeCoordinatorReaction();
        SourceChangeBus::subscribe($reaction);
        Database::sqlRun('ALTER TABLE `hilos_account_operation` ADD CONSTRAINT `reject_crossed_end` CHECK (`outcome` IS NULL)');
        try {
            $this->refused(DatabaseException::class, fn () => $this->deliver($carried));
            self::assertSame($before, $this->operationFacts());
            self::assertFalse(Database::hasOpenTransactions());
            self::assertSame([], $reaction->seen);
            self::assertSame([], $this->frames());
        } finally {
            Database::sqlRun('ALTER TABLE `hilos_account_operation` DROP CONSTRAINT `reject_crossed_end`');
        }
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        self::assertSame([], $this->frames());
        $this->finishExchange($operation);
        $after = $this->operationFacts();
        unset($before['hilos_account_operation'], $after['hilos_account_operation']);
        self::assertSame($before, $after);
    }

    /** A send fault after the carried result commits cannot repeat notes work or hold cleanup behind a notification. */
    public function testProjectAnswerSendFailureKeepsCommittedWorkAndDriveOnlyAsksForMissingCleanup(): void
    {
        [$operation, $give, $project, $cleanup, $giveAnswer] = $this->projectCleanupWindow();
        $this->project->failAnswer = true;
        try {
            $this->refused(RuntimeException::class, fn () => $this->deliver($project));
        } finally {
            $this->project->failAnswer = false;
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], $this->frames());
        self::assertSame([7, 7, 7], $this->noteOwners());
        self::assertNull($this->row($operation)['finished_at']);
        $giveResult = Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray();
        $projectResult = Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->toArray();
        $this->deliver($giveAnswer);
        self::assertSame(['cleanup'], $this->keys($this->frames()));
        $this->freshContext();
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        [$recovered] = $this->frames();
        self::assertSame($cleanup->data->data->assignmentId, $recovered->data->data->assignmentId);
        $this->deliver($recovered);
        $this->finishExchange($operation);
        self::assertSame($giveResult, Hilos::$db->accountOperationResults->byAssignment($give->data->data->assignmentId)->toArray());
        self::assertSame($projectResult, Hilos::$db->accountOperationResults->byAssignment($project->data->data->assignmentId)->toArray());
    }

    /** Refused asks travel through the registered project route without doing notes work. */
    public function testProjectAskBeforeGiveAfterCancelAndWithForeignCoordinatesNeverMovesRows(): void
    {
        $this->activateProject();
        $operation = $this->open();
        $prepares = $this->frames();
        [$initial] = AccountOperationExpectedSet::of($operation->id)->expected();
        $this->assertProjectRefused($initial);
        foreach ($prepares as $prepare) {
            $this->deliver($prepare);
            $this->deliver($this->frames()[0]);
        }
        $this->frames();
        self::assertSame('go', $this->row($operation)['stage']);
        $this->assertProjectRefused($initial);
        self::assertSame(AccountMergeCancellationState::WaitingOwners, $this->asHolder(fn () => $this->holder->cancelMerge($operation)));
        $releases = $this->frames();
        $this->assertProjectRefused($initial);
        $this->finishCancellation($operation, $releases);
        $this->assertProjectRefused($initial);
        $next = $this->open();
        foreach ($this->frames() as $prepare) {
            $this->deliver($prepare);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        $this->deliver($give);
        [$nextInitial] = AccountOperationExpectedSet::of($next->id)->expected();
        $projects = $this->drainExcept([$nextInitial->assignmentKey]);
        self::assertNotEmpty($projects);
        $this->assertProjectRefused($initial, $next->id);
        $this->assertProjectRefused($nextInitial, $operation->id);
        $this->assertProjectRefused($initial);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($initial->id));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($nextInitial->id));
        self::assertSame([7, 9, 9], $this->noteOwners());
        self::assertNull($this->row($next)['finished_at']);
        $this->deliver($projects[0]);
        $this->finishExchange($next);
    }

    /** @return iterable<string, array{class-string<Hilos>}> Two unavailable transport configurations */
    public static function missingProjects(): iterable
    {
        yield 'missing-owner' => [MergeCoordinatorMissingProjectHilos::class];
        yield 'missing-signal' => [MergeCoordinatorSilentProjectHilos::class];
    }

    /** @param class-string<Hilos> $app Installation that cannot dispatch the declared project part */
    #[DataProvider('missingProjects')]
    public function testMissingProjectTransportKeepsItsDutyOpenWithoutAFallbackWriter(string $app): void
    {
        $this->activateProject();
        $app::initBrowser();
        [$operation, $give] = $this->readyGo();
        $set = AccountOperationExpectedSet::of($operation->id);
        $this->deliver($give);
        $this->drainExcept([]);
        self::assertSame([], $this->frames());
        self::assertSame([7, 9, 9], $this->noteOwners());
        self::assertNull($this->row($operation)['finished_at']);
        self::assertNull($this->row($operation)['outcome']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `owner_agent_id` = ?',
            [MergeCoordinatorProjectOwner::AGENT_TYPE])->rows());
        if ($app === MergeCoordinatorMissingProjectHilos::class) {
            self::assertCount(1, $set->waiting());
            self::assertSame([], $set->expected());
        } else {
            self::assertCount(1, $set->expected());
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($set->expected()[0]->id));
        }
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        $this->drainExcept([]);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([7, 9, 9], $this->noteOwners());
    }

    /** @return iterable<string, array{string}> Invalid answers at the exact point a valid one would permit go */
    public static function foreignAnswers(): iterable
    {
        foreach (['unknown-operation', 'unknown-assignment', 'other-operation', 'other-assignment', 'sender-type', 'sender-index',
            'owner-id-as-source', 'stage', 'missing-result', 'foreign-result-owner', 'assignment-owner', 'assignment-index'] as $case) {
            yield $case => [$case];
        }
    }

    /** @param string $case Correlation coordinate or saved binding to invalidate */
    #[DataProvider('foreignAnswers')]
    public function testHolderRejectsForeignAnswersWithoutBorrowingAnotherOperationsProgress(string $case): void
    {
        $operation = $this->open();
        $answers = [];
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            array_push($answers, ...$this->frames());
        }
        $other = $this->open(survivor: 11, loser: 13);
        $otherAsks = $this->frames();
        $valid = $answers[1];
        $payload = $valid->data->data;
        $forgedData = new AgentSignalData(new AccountOperationAnswerSignalData(
            match ($case) { 'unknown-operation' => PHP_INT_MAX, 'other-operation' => $other->id, default => $operation->id },
            $case === 'stage' ? AccountMergeStage::Go->value : $payload->stage,
            match ($case) {
                'unknown-assignment' => PHP_INT_MAX,
                'other-assignment' => $otherAsks[0]->data->data->assignmentId,
                default => $payload->assignmentId,
            },
            AccountOperationAnswerKind::Done,
        ));
        $source = match ($case) {
            'sender-type' => new SignalSource(SignalSource::AGENT, MergeCoordinatorProjectOwner::AGENT_TYPE),
            'sender-index' => new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, '11'),
            'owner-id-as-source' => new SignalSource(HilosAgentType::HILOS_USER . ':9'),
            default => $valid->signalSource,
        };
        $forged = new SignalDTO($source, $valid->signalType, $valid->signalName, $forgedData, $valid->meta);
        if ($case === 'missing-result') {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$payload->assignmentId]);
            $this->freshContext();
        } elseif ($case === 'foreign-result-owner') {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                [HilosAgentType::HILOS_USER . ':11', $payload->assignmentId]);
            $this->freshContext();
        } elseif (in_array($case, ['assignment-owner', 'assignment-index'], true)) {
            $column = $case === 'assignment-owner' ? 'owner_type' : 'owner_index';
            Database::sqlRun('UPDATE `hilos_account_operation_assignment` SET `' . $column . '` = ? WHERE `id` = ?',
                [$case === 'assignment-owner' ? MergeCoordinatorProjectOwner::AGENT_TYPE : '11', $payload->assignmentId]);
            $this->freshContext();
        }
        $before = $this->operationFacts();
        $this->deliver($forged);
        self::assertSame([], $this->frames());
        self::assertSame($before, $this->operationFacts());
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertSame('preparing', $this->row($other)['stage']);
    }

    /** @return iterable<string, array{string}> A carried address still needs its exact durable proof */
    public static function carriedAnswerDefects(): iterable
    {
        yield 'missing-proof' => ['missing'];
        yield 'foreign-proof-owner' => ['owner'];
        yield 'wrong-project-sender' => ['sender'];
    }

    /** @param string $defect Invalid notification or durable result at the last project answer */
    #[DataProvider('carriedAnswerDefects')]
    public function testCarriedProjectAnswerCannotCloseEndWithoutItsExactSavedBinding(string $defect): void
    {
        $this->activateProject();
        [$operation, $give] = $this->readyGo();
        [$initial] = AccountOperationExpectedSet::of($operation->id)->expected();
        $this->deliver($give);
        $projects = $this->drainExcept([$initial->assignmentKey]);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        $this->deliver($projects[0]);
        [$answer] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Done, $answer->data->data->answer);
        self::assertSame([7, 7, 7], $this->noteOwners());
        if ($defect === 'missing') {
            Database::sqlRun('DELETE FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$initial->id]);
        } elseif ($defect === 'owner') {
            Database::sqlRun('UPDATE `hilos_account_operation_result` SET `owner_agent_id` = ? WHERE `assignment_id` = ?',
                [HilosAgentType::HILOS_SESSIONS_LIBRARY, $initial->id]);
        } else {
            $answer = new SignalDTO(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, (string)self::LOSER),
                $answer->signalType, $answer->signalName, $answer->data, $answer->meta);
        }
        $this->freshContext();
        $before = $this->operationFacts();
        $this->deliver($answer);
        self::assertSame([], $this->frames());
        self::assertSame($before, $this->operationFacts());
        self::assertNull($this->row($operation)['outcome']);
        self::assertNull($this->row($operation)['finished_at']);
    }

    /** Old personal stages never receive the project-only carry exception, even with saved proofs. */
    public function testOldPersonalPrepareReleaseAndClosedAnswersCannotAdvanceTheCurrentGo(): void
    {
        $this->activateProject();
        $operation = $this->open();
        $this->frames();
        $this->asHolder(fn () => $this->holder->cancelMerge($operation));
        $releaseAnswers = [];
        foreach ($this->frames() as $release) {
            $this->deliver($release);
            array_push($releaseAnswers, ...$this->frames());
        }
        foreach ($releaseAnswers as $answer) {
            $this->deliver($answer);
        }
        self::assertSame('not_started', $this->row($operation)['outcome']);
        [$current, $give] = $this->readyGo();
        $this->deliver($give);
        $held = $this->drainExcept([AccountOperationExpectedSet::of($current->id)->expected()[0]->assignmentKey]);
        self::assertNotEmpty($held);
        self::assertNull($this->row($current)['finished_at']);
        $prepare = Hilos::$db->accountOperationAssignments->byIdentity(
            $current->id, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareLoser->value,
        );
        // Deliberately forge an old-stage release on go; the write API correctly refuses to create it there.
        Database::sqlRun('INSERT INTO `hilos_account_operation_assignment`'
            . ' (`operation_id`, `stage`, `assignment_key`, `owner_type`, `owner_index`, `user_id`, `payload_json`, `created_at`)'
            . ' SELECT ?, `stage`, `assignment_key`, `owner_type`, `owner_index`, `user_id`, `payload_json`, `created_at`'
            . ' FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$current->id, $releaseAnswers[0]->data->data->assignmentId]);
        $release = Hilos::$db->accountOperationAssignments->byIdentity(
            $current->id, AccountMergeStage::Cancelling->value, AccountMergeDuty::ReleaseLoser->value,
        );
        self::assertNotNull($release);
        Database::sqlRun('INSERT INTO `hilos_account_operation_result` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
            . ' SELECT ?, `owner_agent_id`, `payload_json`, `created_at` FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
            [$release->id, $releaseAnswers[0]->data->data->assignmentId]);
        foreach ([$prepare, $release] as $assignment) {
            $this->asPerson(self::LOSER, static fn (AbstractUserAgent $agent) => $agent->sendToAgent(
                HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
                new AccountOperationAnswerSignalData($current->id, $assignment->stage, $assignment->id, AccountOperationAnswerKind::Done),
            ));
            [$answer] = $this->frames();
            $before = $this->operationFacts();
            $this->deliver($answer);
            self::assertSame([], $this->frames());
            self::assertSame($before, $this->operationFacts());
        }
        foreach ($releaseAnswers as $answer) {
            $before = $this->operationFacts();
            $this->deliver($answer);
            self::assertSame([], $this->frames());
            self::assertSame($before, $this->operationFacts());
        }
        $this->deliver($held[0]);
        $this->finishExchange($current);
        $before = $this->operationFacts();
        $this->deliver($give);
        foreach ($this->frames() as $answer) {
            $this->deliver($answer);
        }
        self::assertSame($before, $this->operationFacts());
        self::assertSame([], $this->frames());
    }

    /** Installs the project before opening and seeds actual survivor and loser rows. */
    private function activateProject(): void
    {
        MergeCoordinatorProjectHilos::initBrowser();
        $this->project = new MergeCoordinatorProjectOwner();
        OwnershipDeclaration::claimAll($this->project);
        Database::sqlRun('INSERT INTO `' . AccountMergeProjectNote::_table . '` (`user_id`) VALUES (7), (9), (9)');
    }

    /** @return list<int> Actual project effect in stable primary-key order */
    private function noteOwners(): array
    {
        return array_map('intval', array_column(Database::sql(
            'SELECT `user_id` FROM `' . AccountMergeProjectNote::_table . '` ORDER BY `id`',
        )->rows(), 'user_id'));
    }

    /**
     * @param list<string> $keys Assignment asks withheld from the finite real exchange
     * @return list<SignalDTO> Held transport envelopes, with no synthetic result writes
     */
    private function drainExcept(array $keys): array
    {
        $held = [];
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                return $held;
            }
            foreach ($frames as $frame) {
                if ($frame->data->data instanceof AccountOperationAskSignalData && in_array($this->keys([$frame])[0], $keys, true)) {
                    $held[] = $frame;
                } else {
                    $this->deliver($frame);
                }
            }
        }
        self::fail('The held-proof exchange must settle without a retry loop.');
    }

    /**
     * @return array{AccountOperation, SignalDTO, SignalDTO, SignalDTO, SignalDTO} Receipt committed, project and cleanup asks held
     */
    private function projectCleanupWindow(): array
    {
        $this->activateProject();
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        [$operation, $give] = $this->readyGo();
        [$initial] = AccountOperationExpectedSet::of($operation->id)->expected();
        $this->deliver($give);
        [$handover, $giveAnswer] = $this->frames();
        $this->deliver($handover);
        [$receipt] = $this->frames();
        $this->deliver($receipt);
        $held = $this->drainExcept([$initial->assignmentKey, AccountMergeDuty::Cleanup->value]);
        self::assertSame([$initial->assignmentKey, 'cleanup'], $this->keys($held));
        self::assertNotNull(AccountMergeReceiveResult::forOperation($operation));
        self::assertNull($this->row($operation)['finished_at']);

        return [$operation, $give, $held[0], $held[1], $giveAnswer];
    }

    /**
     * @param AccountOperationAssignment $assignment Original project expectation, never a successful marker
     * @param ?int $foreignOperation Deliberate operation mismatch at the transport boundary
     */
    private function assertProjectRefused(AccountOperationAssignment $assignment, ?int $foreignOperation = null): void
    {
        $this->asHolder(fn () => $this->holder->sendToAgent(MergeCoordinatorProjectOwner::ACCOUNT_OPERATION_SIGNAL,
            new AccountOperationAskSignalData($foreignOperation ?? $assignment->operationId,
                $assignment->stage, $assignment->id, $assignment->ownerIndex)));
        [$ask] = $this->frames();
        $this->deliver($ask);
        [$answer] = $this->frames();
        self::assertSame(AccountOperationAnswerKind::Refused, $answer->data->data->answer);
        $this->deliver($answer);
        self::assertSame([], $this->frames());
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($assignment->id));
        self::assertSame([7, 9, 9], $this->noteOwners());
    }

    /** @return array<string, list<array<string, mixed>>> Durable facts that an unusable answer must not change */
    private function operationFacts(): array
    {
        $facts = [];
        foreach (['hilos_account_operation', 'hilos_account_operation_assignment', 'hilos_account_operation_result',
            'hilos_account_merge_copy', 'hilos_account_merge_fence', 'hilos_user_merge', AccountMergeProjectNote::_table] as $table) {
            $facts[$table] = Database::sql('SELECT * FROM `' . $table . '`')->rows();
        }

        return $facts;
    }

    /** @param AccountOperation $operation Merge whose queued real handlers must settle without manual advance */
    private function finishExchange(AccountOperation $operation): void
    {
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->frames();
            if ($frames === []) {
                self::assertSame('completed', $this->row($operation)['outcome']);
                self::assertNotNull($this->row($operation)['finished_at']);
                self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());

                return;
            }
            foreach ($frames as $frame) {
                $this->deliver($frame);
            }
        }
        self::fail('The finite handler exchange must settle without replay loops.');
    }

    /**
     * @param ?AccountMergeChoice $choice Immutable choices
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @return AccountOperation Prepared holder opening
     */
    private function open(?AccountMergeChoice $choice = null, int $survivor = self::SURVIVOR, int $loser = self::LOSER): AccountOperation
    {
        return $this->asHolder(fn () => $this->holder->openMerge($survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null)));
    }

    /** @return array{AccountOperation, SignalDTO} Real preparation exchange, stopping before the queued give */
    private function readyGo(): array
    {
        $operation = $this->open();
        $initial = AccountOperationExpectedSet::of($operation->id)->expected();
        foreach ($initial as $assignment) {
            self::assertSame('preparing', $assignment->stage);
        }
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame(['give'], $this->keys([$give]));
        self::assertSame(array_map(static fn (AccountOperationAssignment $assignment): array => $assignment->toArray(), $initial),
            array_map(static fn (AccountOperationAssignment $assignment): array => $assignment->toArray(),
                AccountOperationExpectedSet::of($operation->id)->expected()));

        return [$operation, $give];
    }

    /**
     * @param AccountOperation $operation Cancelling merge
     * @param list<SignalDTO> $releases The two independently addressed release asks
     */
    private function finishCancellation(AccountOperation $operation, array $releases): void
    {
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($releases));
        self::assertSame('cancelling', $this->row($operation)['stage']);
        $this->deliver($releases[0]);
        $this->deliver($this->frames()[0]);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->deliver($releases[1]);
        $this->deliver($this->frames()[0]);
        self::assertSame('not_started', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        self::assertSame([], $this->frames());
    }

    /** @return list<SignalDTO> Real queued envelopes, excluding unrelated sync announcements */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /** @param SignalDTO $queued Real frame, serialized and topology-hydrated as it is at worker delivery */
    private function deliver(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $data = Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
        $sender = SignalSource::describe($frame->signalSource);
        if ($data->data instanceof AccountOperationAnswerSignalData) {
            $this->asHolder(fn () => $this->holder->onSignalAgent($data, $sender, $frame->signalName->getName()));
        } elseif ($frame->signalName->getName() === MergeCoordinatorProjectOwner::ACCOUNT_OPERATION_SIGNAL) {
            self::assertNotNull($this->project);
            ExecutionContext::run(new ExecutionFrame(agentId: $this->project->getId()),
                fn () => $this->project->onSignalAgent($data, $sender, $frame->signalName->getName()));
        } else {
            self::assertTrue($data->data instanceof AccountOperationAskSignalData || $data->data instanceof AccountMergeHandoverSignalData);
            $this->asPerson((int)$data->data->ownerIndex, static fn (AbstractUserAgent $agent) =>
                $agent->onSignalAgent($data, $sender, $frame->signalName->getName()));
        }
    }

    /**
     * @param list<SignalDTO> $frames Asks from the queue
     * @return list<string> Stored assignment keys in transport order
     */
    private function keys(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): string =>
            Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId]->assignmentKey, $frames);
    }

    /**
     * @template T
     * @param callable(): T $work Work owned by the canonical session holder
     * @return T Work result
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $work);
    }

    /**
     * @param AccountOperation $operation Merge to observe independently of its cached facade
     * @return array<string, mixed> Persisted operation row
     */
    private function row(AccountOperation $operation): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->row();
    }

    /** Mounts the framework AUTH collections without changing the parent configuration. */
    private function freshContext(): void
    {
        Hilos::$db = new MergeCoordinatorContext();
        Hilos::$db->configure();
    }

    /**
     * @param class-string<Throwable> $expected Expected failure category
     * @param callable $work Refused operation
     * @return Throwable Observed failure
     */
    private function refused(string $expected, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $failure) {
            self::assertInstanceOf($expected, $failure);

            return $failure;
        }
        self::fail('The invalid operation must be refused.');
    }

    /** @param bool $down Whether to drop the supporting tables in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
                . $table . $suffix . '.sql'));
        }
    }
}

/** Thin access to the protected prepared entries; all transport and participant dispatch is inherited. */
final class MergeCoordinatorHolder extends AbstractSessionsLibraryAgent
{
    public ?AccountMergeDuty $heldDuty = null;
    public ?ValidationException $refusePreflight = null;
    public bool $projectPreflightAsked = false;

    /**
     * @param int $survivorUserId Survivor named by the opening
     * @param int $loserUserId Loser named by the opening
     */
    protected function assertMergeable(int $survivorUserId, int $loserUserId): void
    {
        parent::assertMergeable($survivorUserId, $loserUserId);
        $this->projectPreflightAsked = true;
        if ($this->refusePreflight !== null) {
            throw $this->refusePreflight;
        }
    }

    /**
     * @param int $assignmentId Real inline assignment, held only by the existing wait outcome
     * @return AccountOperationResult Result written by the production handler after the hold is lifted
     */
    protected function applyAccountSessionsPart(int $assignmentId): AccountOperationResult
    {
        if ($this->heldDuty !== null
            && Hilos::$db->accountOperationAssignments[$assignmentId]->assignmentKey === $this->heldDuty->value) {
            throw new AccountMergeReceiveWaitsException();
        }

        return parent::applyAccountSessionsPart($assignmentId);
    }

    /**
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @param AccountMergeChoice $choice Immutable choices
     * @return AccountOperation Prepared opening or exact repeat
     */
    public function openMerge(int $survivor, int $loser, AccountMergeChoice $choice): AccountOperation
    {
        return $this->openAccountMerge($survivor, $loser, $choice);
    }

    /** @param AccountOperation $operation Prepared merge to reenter */
    public function driveMerge(AccountOperation $operation): void
    {
        $this->driveAccountMerge($operation);
    }

    /**
     * @param AccountOperation $operation Merge whose durable gate decides cancellation
     * @return AccountMergeCancellationState Cancellation verdict
     */
    public function cancelMerge(AccountOperation $operation): AccountMergeCancellationState
    {
        return $this->cancelAccountMerge($operation);
    }
}

/** Real personal owner registered for every delivered indexed ask. */
final class MergeCoordinatorUser extends AbstractUserAgent
{
    public bool $failHandover = false;

    /** @var (Closure(AccountMergePart): void)|null Project work supplied to the real Give hook */
    public ?Closure $beforeGive = null;

    /**
     * @param string $signalName Real routed frame name
     * @param SignalDataInterface $data Typed transport payload
     * @throws RuntimeException When the test interrupts only the post-commit handover send
     * @throws InvalidArgumentException When the signal name is invalid
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER) {
            Assert::assertFalse(Database::hasOpenTransactions(), 'Source handover must leave only after give commit.');
            if ($this->failHandover) {
                throw new RuntimeException('Private transport failure.');
            }
        }
        parent::sendToAgent($signalName, $data);
    }

    /** @param AccountMergePart $part Operation coordinates passed to the project hook */
    protected function beforeMergeGive(AccountMergePart $part): void
    {
        if ($this->beforeGive !== null) {
            ($this->beforeGive)($part);
        }
    }
}

/** The test installs only the owners its real prepared exchange delivers to. */
abstract class MergeCoordinatorHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => MergeCoordinatorUser::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => MergeCoordinatorHolder::class],
    ];
}

/** Parent AUTH configuration with the existing project test model mounted alongside it. */
final class MergeCoordinatorContext extends HilosDbContext
{
    /** Mounts the framework first, then the reused notes collection. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(AccountMergeProjectNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** Declares the existing notes under the local, kind-aware participant. */
abstract class MergeCoordinatorProjectDirectory extends AccountMergeProjectDirectory
{
    /** @return list<AccountDataOwner> One registered singleton over the existing notes */
    protected static function owners(): array
    {
        return array_map(static fn (AccountDataOwner $owner): AccountDataOwner => new AccountDataOwner(
            $owner->key, $owner->collections, MergeCoordinatorProjectOwner::AGENT_TYPE, false, $owner->hasRowsOf,
        ), parent::owners());
    }
}

/** The project exists before the real opener records its initial immutable expectation. */
abstract class MergeCoordinatorProjectHilos extends MergeCoordinatorHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = MergeCoordinatorProjectDirectory::class;
    public const array AGENTS = parent::AGENTS + [
        MergeCoordinatorProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => MergeCoordinatorProjectOwner::class],
    ];
}

/** A configured directory whose project worker is absent. */
abstract class MergeCoordinatorMissingProjectHilos extends MergeCoordinatorProjectHilos
{
    public const array AGENTS = MergeCoordinatorHilos::AGENTS;
}

/** A project worker with no declared account operation transport. */
abstract class MergeCoordinatorSilentProjectHilos extends MergeCoordinatorProjectHilos
{
    public const array AGENTS = MergeCoordinatorHilos::AGENTS + [
        MergeCoordinatorProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => MergeCoordinatorSilentProjectOwner::class],
    ];
}

/** Local notes participant with the same kind dispatch as a production project owner. */
class MergeCoordinatorProjectOwner extends AbstractAgent implements AccountMergeProjectOwner, AccountErasureProjectOwner
{
    public const string AGENT_TYPE = 'merge_coordinator_notes';
    public const string ACCOUNT_OPERATION_SIGNAL = 'mergecoordinatornotes_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];
    public const array OWNS_DB = [AccountMergeProjectNotes::COLLECTION_KEY => [TruthSourceOperation::Update, TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    public bool $failAnswer = false;

    /**
     * @param string $signalName Registered answer signal
     * @param SignalDataInterface $data Real answer whose participant work has already committed
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($this->failAnswer && $signalName === HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER) {
            Assert::assertFalse(Database::hasOpenTransactions());
            throw new RuntimeException('Project answer transport failed after commit.');
        }
        parent::sendToAgent($signalName, $data);
    }

    /**
     * @param string $collection Result collection whose set is this participant's
     * @return string Canonical result author
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountMergePart $part Immutable operation pair passed by the existing project step
     * @return array<string, int> Actual moved notes
     */
    public function mergeAccountPart(AccountMergePart $part): array
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);
        Assert::assertInstanceOf(AccountMergeProjectNotes::class, $notes);
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->loserUserId), false);
        foreach ($keys as $key) {
            $note = $notes[$key];
            $note->userId = $part->survivorUserId;
            $note->sync();
        }

        return ['notes' => count($keys)];
    }

    /**
     * @param AccountErasurePart $part Account whose notes the erasure step asks to remove
     * @return AccountErasure Actual removed notes, without files
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $notes = Hilos::$db->getObjectCollection(AccountMergeProjectNotes::COLLECTION_KEY);
        Assert::assertInstanceOf(AccountMergeProjectNotes::class, $notes);
        $keys = iterator_to_array($notes->loadByColumn(AccountMergeProjectNote::user_id, $part->userId), false);
        foreach ($keys as $key) {
            $notes[$key]->delete();
        }

        return new AccountErasure(['notes' => count($keys)], []);
    }

    /**
     * @param AgentSignalData $data Generic assignment ask
     * @param string $sender Canonical holder source
     * @param string $name Registered project step
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::ACCOUNT_OPERATION_SIGNAL:
                $ask = $data->data;
                if (!$ask instanceof AccountOperationAskSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountOperationAskSignalData::class, $ask);
                }
                Assert::assertSame(SignalSource::describe(new SignalSource(SignalSource::AGENT,
                    HilosAgentType::HILOS_SESSIONS_LIBRARY)), $sender);
                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult => match ($assignment->operation->kind) {
                        AccountOperationKind::Merge => AccountMergeProjectStep::run($this, $assignment->id),
                        AccountOperationKind::Erasure => AccountErasureProjectStep::run($this, $assignment->id),
                    },
                    $this->getId(),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** No resources outlive a delivered test frame. */
    public function onStop(): void
    {
    }
}

/** The inherited merge API remains usable, but there is no transport declaration. */
final class MergeCoordinatorSilentProjectOwner extends MergeCoordinatorProjectOwner
{
    public const string ACCOUNT_OPERATION_SIGNAL = '';
    public const array AGENT_SIGNALS = [];
}

/** Commit observer used to prove the failed go transaction announced no stage or assignment. */
final class MergeCoordinatorReaction implements SourceChangeSubscriberInterface
{
    /** @var list<string> Source keys announced after commit */
    public array $seen = [];

    /**
     * @param SourceChange $change Committed source fact
     * @param SourceChangeProvenance $provenance Actual publisher provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change->sourceKey;
    }
}
