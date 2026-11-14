<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartResult;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Entity\Item\NotificationDelivery;
use Hilos\Database\Entity\Item\NotificationPreference;
use Hilos\Database\Entity\Item\PushSubscription;
use Hilos\Database\Entity\Item\UserPhoto;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Hilos;
use Hilos\Legal\LegalDocument;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Users\AccountErasure;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The person's agent deletes its own child sets as its part of the erasure of an account (HIL-1941).
 *
 * The session holder opens the erasure of a root account's request, whose census expects the
 * person's part from the person's agent; the root's agent starts it, every account of the circle
 * captures, and then the agent carries its part on the assignment the census wrote. Every write
 * runs in the frame of the agent whose class declares it, and commits for real. The installation
 * keeps a files registry, so a photo's file leaves in a frame after the commit, and one pooled
 * letter channel whose deliveries have an owner.
 */
final class AccountErasurePersonalPartIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Tables the part reaches beyond the base's, raised over it in dependency order. */
    private const array TABLES = [
        'hilos_passkey_credential',
        'hilos_notification',
        'hilos_notification_delivery',
        'hilos_notification_preference',
        'hilos_push_subscription',
        'hilos_file',
        'hilos_user_photo',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** The person's child sets the part deletes, each cut by its user id column. */
    private const array PERSONAL_SETS = [
        'hilos_passkey_credential',
        'hilos_identity',
        'hilos_second_factor',
        'hilos_second_factor_backup_code',
        'hilos_second_factor_reset',
        'hilos_second_factor_setting',
        'hilos_second_factor_trust',
        'hilos_step_up',
        'hilos_legal_acceptance',
        'hilos_notification',
        'hilos_notification_preference',
        'hilos_push_subscription',
        'hilos_user_photo',
    ];

    /** Agent id the session holder writes the operations under. */
    private const string HOLDER_ID = 'erasure-personal-part-holder';

    /** Prefix of the start's assignment key, followed by the account. */
    private const string START_KEY = 'start:';

    /** Payload of the start's assignment, which the start does not read. */
    private const string START_PAYLOAD = '{}';

    /** The rows keep epoch milliseconds. */
    private const int CREATED_AT = 1790416800000;

    /** 2036-01-01 00:00:00 UTC: the removal wait and the trust are still ahead. */
    private const int FUTURE = 2082758400000;

    /** The legal acceptances keep a timestamp column. */
    private const int ACCEPTED_AT = 1790416800000;

    /** Saved key of a letter delivery: the second of the channel's four senders owns it. */
    private const int ROUTING_KEY = 5;

    private ErasurePersonalPartTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    private ?HilosFiles $previousFiles = null;

    private string $previousApp;

    /** How many times a person's agent deleted its child sets. */
    private int $childErasures = 0;

    /** What the test stand raises right after the child sets are deleted, or null for nothing. */
    private ?LogicException $failure = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousFiles = Hilos::$files;
        Hilos::$sr = new SignalRouter();
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        ErasurePersonalPartTestHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new ErasurePersonalPartTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb(ErasurePersonalPartTestSessionsHolder::class, self::HOLDER_ID);
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(down: true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            foreach ([$this->users->getId(), self::HOLDER_ID] as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$files = $this->previousFiles;
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();

            parent::tearDown();
        }
    }

    /**
     * After the start and the capture the part deletes all thirteen sets of the person and none of
     * another person's, and its result is committed with the deletions. The second-factor settings
     * go under the agent's own claim over its set, in the agent's frame and no other.
     */
    public function testAfterTheCaptureThePartErasesThePersonsSetsAndCommitsItsResult(): void
    {
        $userId = $this->seedPerson();
        $fileId = self::seedSets($userId, photo: true);
        $otherId = $this->seedPerson();
        self::seedSets($otherId, photo: true);
        $others = self::setsOf($otherId);
        self::assertNotContains(0, $others);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        self::assertSame(
            "expect:personal:hilos_user:{$userId}:{$userId}",
            Hilos::$db->accountOperationAssignments[$assignmentId]->assignmentKey,
        );
        self::assertSame(1, self::setsOf($userId)['hilos_second_factor_setting']);

        $result = $this->runPart($userId, $assignmentId);

        $payload = "{\"rows\":{\"hilos_user_photo\":1},\"files\":[{$fileId}]}";
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($payload, $result->payloadJson);
        self::assertSame([[$assignmentId, HilosAgentType::HILOS_USER . ':' . $userId, $payload]], self::storedPartResults());
        self::assertSame(array_fill_keys(self::PERSONAL_SETS, 0), self::setsOf($userId));
        self::assertSame($others, self::setsOf($otherId));
        self::assertTrue(self::personExists($userId), 'The person\'s own row is not this part\'s');
        self::assertSame(1, $this->childErasures);
    }

    /** The photo's file leaves for the files library in one frame, only once the part has committed. */
    public function testThePhotosFileLeavesOnceThePartCommits(): void
    {
        $userId = $this->seedPerson();
        $fileId = self::seedSets($userId, photo: true);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        self::removedFiles();

        $this->runPart($userId, $assignmentId);

        self::assertSame([[$fileId]], self::removedFiles());
        self::assertSame(1, self::fileRows($fileId), 'The registry row is the files library\'s to remove');
    }

    /** Without a photo the part names nothing, as an empty object and list, and no frame leaves. */
    public function testWithoutAPhotoThePartNamesNothingAndSendsNoFrame(): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: false);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        self::removedFiles();

        $result = $this->runPart($userId, $assignmentId);

        self::assertSame('{"rows":{},"files":[]}', $result->payloadJson);
        self::assertSame([], self::removedFiles());
        self::assertSame(array_fill_keys(self::PERSONAL_SETS, 0), self::setsOf($userId));
    }

    /** @return array<string, array{list<string>}> Tables of a feature the project did not activate, in raise order */
    public static function inactiveTables(): array
    {
        return [
            'photo' => [[UserPhoto::_table]],
            'notifications' => [[Notification::_table, NotificationDelivery::_table]],
            'channel choices' => [[NotificationPreference::_table]],
            'push subscriptions' => [[PushSubscription::_table]],
        ];
    }

    /**
     * In a project that did not activate a table the part does not reach for it: it names nothing,
     * no frame leaves, and every other set of the person is deleted.
     *
     * @param list<string> $tables Tables the project lacks, dropped after the person's rows are seeded
     */
    #[DataProvider('inactiveTables')]
    public function testWithoutAnInactiveTableThePartSkipsItAndErasesTheRest(array $tables): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: false);
        self::stubs(down: true, tables: $tables);
        Schema::reset();
        Schema::initialize();
        // The sets seeded: every one but the dropped tables and the photo the person does not have.
        $sets = array_values(array_diff(self::PERSONAL_SETS, $tables, [UserPhoto::_table]));
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        self::removedFiles();
        self::assertNotContains(0, self::setsOf($userId, $sets));

        $result = $this->runPart($userId, $assignmentId);

        self::assertSame('{"rows":{},"files":[]}', $result->payloadJson);
        self::assertSame([], self::removedFiles());
        self::assertSame(array_fill_keys($sets, 0), self::setsOf($userId, $sets));
        self::assertSame(1, $this->childErasures);
    }

    /** Before the person's addresses are captured the part waits for them, and deletes nothing. */
    public function testWithoutTheCaptureThePartWaitsForTheAddresses(): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: true);
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);

        $wait = $this->assertPartRefused(
            $userId,
            self::personalPart($erasure, $userId),
            'The person\'s erasure part waits for its addresses to be captured.',
        );

        self::assertInstanceOf(AccountErasureUncapturedException::class, $wait);
        self::assertSame([], $wait->notificationIds);
        self::assertSame(0, $this->childErasures);
    }

    /**
     * A notification whose delivery no portion names holds the part back by its number, and nothing
     * is deleted; once the coordinator hands that delivery out, the part commits.
     */
    public function testAnUncapturedDeliveryHoldsThePartUntilItIsHandedOut(): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: false);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        $notificationId = self::seedNotification($userId);
        self::seedDelivery($notificationId);

        $wait = $this->assertPartRefused(
            $userId,
            $assignmentId,
            "The person's erasure part waits for the deliveries of notifications #{$notificationId} to be captured.",
        );

        self::assertInstanceOf(AccountErasureUncapturedException::class, $wait);
        self::assertSame([$notificationId], $wait->notificationIds);

        $this->recordAfterStart($erasure);
        $result = $this->runPart($userId, $assignmentId);

        self::assertSame('{"rows":{},"files":[]}', $result->payloadJson);
        self::assertSame(array_fill_keys(self::PERSONAL_SETS, 0), self::setsOf($userId));
    }

    /**
     * The test stand fails the part right after the child sets: every row is back, and neither a
     * result nor a file frame is left. The repeat with a healthy stand commits once.
     */
    public function testAFailureAfterTheChildSetsRollsThePartBackAndTheRepeatCommitsOnce(): void
    {
        $userId = $this->seedPerson();
        $fileId = self::seedSets($userId, photo: true);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        self::removedFiles();
        $sets = self::setsOf($userId);
        $this->failure = new LogicException('The person\'s part failed after its child sets.');

        try {
            $this->runPart($userId, $assignmentId);
            self::fail('The part did not fail');
        } catch (LogicException $caught) {
            self::assertSame($this->failure, $caught);
        }

        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($sets, self::setsOf($userId));
        self::assertNotContains(0, $sets);
        self::assertSame([], self::storedPartResults());
        self::assertSame([], self::removedFiles());

        $this->failure = null;
        $result = $this->runPart($userId, $assignmentId);

        self::assertSame("{\"rows\":{\"hilos_user_photo\":1},\"files\":[{$fileId}]}", $result->payloadJson);
        self::assertCount(1, self::storedPartResults());
        self::assertSame([[$fileId]], self::removedFiles());
        self::assertSame(array_fill_keys(self::PERSONAL_SETS, 0), self::setsOf($userId));
    }

    /**
     * A new agent on the same assignment after the deletions answers the saved result: it deletes
     * nothing written since, and sends no second frame.
     */
    public function testANewAgentAfterThePartAnswersTheSavedResult(): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: true);
        $erasure = $this->openCaptured($userId);
        $assignmentId = self::personalPart($erasure, $userId);
        $first = $this->runPart($userId, $assignmentId);
        self::removedFiles();
        $stored = self::storedPartResults();
        self::seedNotification($userId);
        $this->releasePersonAgents();

        $repeat = $this->runPart($userId, $assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame($first->payloadJson, $repeat->payloadJson);
        self::assertSame($stored, self::storedPartResults());
        self::assertSame([], self::removedFiles());
        self::assertSame(1, self::setsOf($userId)['hilos_notification'], 'The work is not done again');
        self::assertSame(1, $this->childErasures);
    }

    /**
     * An account folded into the root has no request of its own: after the capture its own part
     * deletes its sets, and its merge row and its person row stay for the steps after it.
     */
    public function testAFoldedAccountWithoutItsOwnRequestErasesItsSetsAndKeepsItsMergeRow(): void
    {
        $rootId = $this->seedPerson();
        $foldedId = $this->seedPerson();
        self::seedSets($foldedId, photo: false);
        self::seedMerge($foldedId, $rootId);
        $erasure = $this->openCaptured($rootId);
        self::assertNull(Hilos::$db->accountDeletions->startedOf($foldedId));

        $this->runPart($foldedId, self::personalPart($erasure, $foldedId));

        self::assertSame(array_fill_keys(self::PERSONAL_SETS, 0), self::setsOf($foldedId));
        self::assertSame(
            [$rootId],
            array_map(
                static fn (array $row): int => (int)$row['survivor_user_id'],
                Database::sql('SELECT `survivor_user_id` FROM `hilos_user_merge` WHERE `user_id` = ?', [$foldedId])->rows(),
            ),
        );
        self::assertTrue(self::personExists($foldedId));
    }

    /**
     * Assignments that are not the person's part are refused before any work: one handed out in a
     * merge, the wait for the person's part, the person's capture handed out and not answered yet -
     * an answered one is a saved result, which the step answers as a repeat - another participant's
     * expected part, and the person's part of the account folded into it given to this agent.
     * Nothing is written.
     */
    public function testAnAssignmentThatIsNotThePersonsPartIsRefused(): void
    {
        $userId = $this->seedPerson();
        self::seedSets($userId, photo: true);
        $foldedId = $this->seedPerson();
        self::seedMerge($foldedId, $userId);
        $otherId = $this->seedPerson();
        $erasure = $this->openErasure($userId);
        $this->startRoot($erasure, $userId);
        $this->recordAfterStart($erasure);
        $person = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        $merge = $this->asHolder(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            $userId,
            $otherId,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));
        $expected = new AccountOperationExpectation(AccountErasureParticipantCatalog::PERSONAL, $userId, $person);
        $wait = new AccountOperationExpectation(AccountErasureParticipantCatalog::PERSONAL, $userId, null);
        $another = new AccountOperationExpectation(AccountErasureParticipantCatalog::USERS_LIBRARY, $userId, $person);
        $foreign = new AccountOperationExpectation(AccountErasureParticipantCatalog::PERSONAL, $foldedId, $person);

        $this->assertPartRefused(
            $userId,
            $this->assign($merge, $expected->assignmentKey(), $userId, $userId, $expected->payload()),
            'An account erasure part must belong to an erasure.',
        );
        foreach ([$wait, $another] as $expectation) {
            $this->assertPartRefused(
                $userId,
                $this->assign($erasure, $expectation->assignmentKey(), $userId, $userId, $expectation->payload()),
                'This assignment is not the person\'s erasure part.',
            );
        }
        $this->assertPartRefused(
            $userId,
            self::assignmentId($erasure, AccountErasureCapture::personalKey($userId)),
            'This assignment is not the person\'s erasure part.',
        );
        $this->assertPartRefused(
            $userId,
            $this->assign($erasure, $foreign->assignmentKey(), $userId, $foldedId, $foreign->payload()),
            'An account erasure part must name its own account.',
        );
        self::assertSame(0, $this->childErasures);
    }

    /**
     * The person's agent, whose child sets the case counts and can make fail right after them.
     *
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The agent, not yet claimed
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new ErasurePersonalPartTestUserAgent($userId, function (): void {
            $this->childErasures++;
            if ($this->failure !== null) {
                throw $this->failure;
            }
        });
    }

    /**
     * Opens the erasure of the root's request, starts it, and has every account of its circle
     * capture its addresses.
     *
     * @param int $rootId Account whose deletion is requested
     * @return AccountOperation The open erasure, at the stage the opening left it
     */
    private function openCaptured(int $rootId): AccountOperation
    {
        $erasure = $this->openErasure($rootId);
        $this->startRoot($erasure, $rootId);
        $this->recordAfterStart($erasure);
        foreach ($erasure->circle->toArray() as $userId) {
            $assignmentId = self::assignmentId($erasure, AccountErasureCapture::personalKey($userId));
            $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->captureForErasure($assignmentId));
        }

        return $erasure;
    }

    /**
     * Opens a request a day ahead in the users library's frame, then the erasure that answers it in
     * the session holder's frame.
     *
     * @param int $rootId Account whose deletion is requested
     * @return AccountOperation The open erasure, at the stage the opening left it
     */
    private function openErasure(int $rootId): AccountOperation
    {
        $request = $this->requestDeletion($rootId);

        return $this->asHolder(static function () use ($request): AccountOperation {
            $erasure = AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation;
            self::assertNotNull($erasure);

            return $erasure;
        });
    }

    /**
     * @param int $userId Person whose account is to be deleted
     * @return AccountDeletion The request
     */
    private function requestDeletion(int $userId): AccountDeletion
    {
        $at = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            static fn () => Hilos::$db->accountDeletions->actions->request($userId, $at),
        );
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * Closes the root account: the root's agent starts the erasure on its request.
     *
     * @param AccountOperation $erasure Open erasure of the root's request
     * @param int $rootId Root account
     */
    private function startRoot(AccountOperation $erasure, int $rootId): void
    {
        $assignmentId = $this->assign(
            $erasure,
            self::START_KEY . $rootId,
            $rootId,
            $rootId,
            AccountOperationAssignmentPayload::fromJson(self::START_PAYLOAD),
        );
        $result = $this->asPerson($rootId, static fn (): AccountOperationResult => AccountErasureStart::run($assignmentId));
        self::assertTrue(AccountErasureStartResult::startedOf($result->payload));
    }

    /**
     * Hands out the captures of an erasure in the session holder's frame, the way the coordinator would.
     *
     * @param AccountOperation $erasure Open erasure
     */
    private function recordAfterStart(AccountOperation $erasure): void
    {
        $this->asHolder(
            static fn (): array => AccountErasureCapture::recordAfterStart($erasure, AccountErasureParticipantCatalog::framework()),
        );
    }

    /**
     * Records one assignment at the operation's current stage, written to a person's agent, in the
     * session holder's frame.
     *
     * @param AccountOperation $operation Open operation
     * @param string $key Assignment key
     * @param int $ownerId Person whose agent the assignment is written to
     * @param int $userId Account the assignment is about
     * @param AccountOperationAssignmentPayload $payload Assignment payload
     * @return int Id of the assignment
     */
    private function assign(
        AccountOperation $operation,
        string $key,
        int $ownerId,
        int $userId,
        AccountOperationAssignmentPayload $payload,
    ): int {
        return $this->asHolder(static fn (): int => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $key,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$ownerId),
            $userId,
            $payload,
        )->id);
    }

    /**
     * Runs one step in the session holder's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the holder
     * @return T Whatever the step returns
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), $step);
    }

    /**
     * @param int $userId Account whose agent carries its part
     * @param int $assignmentId The part's assignment
     * @return AccountOperationResult The recorded or saved result
     */
    private function runPart(int $userId, int $assignmentId): AccountOperationResult
    {
        return $this->asPerson(
            $userId,
            static fn (AbstractUserAgent $agent): AccountOperationResult => $agent->eraseAccountPersonalPart($assignmentId),
        );
    }

    /**
     * @param int $userId Account whose agent carries its part
     * @param int $assignmentId Assignment the agent is given
     * @param string $words Words of the refusal
     * @return ValidationException The refusal
     */
    private function assertPartRefused(int $userId, int $assignmentId, string $words): ValidationException
    {
        $sets = self::setsOf($userId);
        $results = self::storedPartResults();
        try {
            $this->runPart($userId, $assignmentId);
        } catch (ValidationException $refusal) {
            self::assertSame($words, $refusal->getMessage());
            self::assertFalse(Database::hasOpenTransactions());
            self::assertSame($sets, self::setsOf($userId));
            self::assertNotSame(0, array_sum($sets), 'The person still had rows to lose');
            self::assertSame($results, self::storedPartResults());

            return $refusal;
        }
        self::fail('The part was not refused');
    }

    /**
     * @param AccountOperation $erasure Open erasure
     * @param int $userId Account of its circle
     * @return int Id of the person's part the census expects of that account's agent
     */
    private static function personalPart(AccountOperation $erasure, int $userId): int
    {
        $parts = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($erasure->id) as $assignment) {
            if (
                AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && AccountOperationExpectation::participantOf($assignment->payload) === AccountErasureParticipantCatalog::PERSONAL
                && $assignment->userId === $userId
            ) {
                $parts[] = $assignment->id;
            }
        }
        self::assertCount(1, $parts);

        return $parts[0];
    }

    /**
     * @param AccountOperation $operation Operation whose assignments are read
     * @param string $key Assignment key
     * @return int Id of the assignment stored under that key
     */
    private static function assignmentId(AccountOperation $operation, string $key): int
    {
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if ($assignment->assignmentKey === $key) {
                return $assignment->id;
            }
        }
        self::fail("No assignment {$key}");
    }

    /**
     * @return int Id of a new person row
     */
    private function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * One row of the person in every set the part deletes, the photo and its registry file aside
     * when the case asks for none.
     *
     * @param int $userId Person
     * @param bool $photo Whether the person has a photo
     * @return ?int The photo's registry file, or null without a photo
     */
    private static function seedSets(int $userId, bool $photo): ?int
    {
        self::seedIdentity($userId, IdentityType::PASSWORD, "person-{$userId}@example.test");
        self::seedIdentity($userId, IdentityType::PASSKEY, "credential-{$userId}");
        $identityId = (int)Database::sql(
            'SELECT `id` FROM `hilos_identity` WHERE `user_id` = ? AND `type` = ?',
            [$userId, IdentityType::PASSKEY],
        )->row()['id'];
        Database::sqlRun(
            'INSERT INTO `hilos_passkey_credential` '
            . '(`created_at`, `identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [self::CREATED_AT, $identityId, $userId, "credential-{$userId}", 'unused-public-key', -7, "handle-{$userId}"],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor` (`user_id`, `label`, `secret`, `confirmed_at`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [$userId, 'Phone', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', self::CREATED_AT, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_backup_code` (`user_id`, `code`, `created_at`) VALUES (?, ?, ?)',
            [$userId, 'abcdefghjk', self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_reset` (`user_id`, `requested_at`, `effective_at`, `notified_at`) VALUES (?, ?, ?, ?)',
            [$userId, self::CREATED_AT, self::FUTURE, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_setting` (`user_id`, `reset_wait_days`, `updated_at`) VALUES (?, ?, ?)',
            [$userId, 10, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`) VALUES (?, ?, ?, ?, ?)',
            [$userId, $userId, self::FUTURE, self::CREATED_AT, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`, `created_at`) VALUES (?, ?, ?, ?, ?)',
            [ProtectedModeRuntime::hashSessionToken("token-{$userId}"), $userId, StepUpOperationKey::DELETE_ACCOUNT, self::FUTURE, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [$userId, LegalDocument::TERMS->value, '2026-09-17', self::ACCEPTED_AT],
        );
        self::seedNotification($userId);
        Database::sqlRun(
            'INSERT INTO `hilos_notification_preference` (`user_id`, `channel`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?)',
            [$userId, MailDeliveryChannel::NAME, self::CREATED_AT, self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_push_subscription` (`user_id`, `endpoint`, `p256dh`, `auth`, `endpoint_hash`, `created_at`, `revision`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$userId, "https://push.example.test/{$userId}", 'key', 'secret', hash('sha256', (string)$userId), self::CREATED_AT],
        );
        if (!$photo) {
            return null;
        }
        Database::sql(
            'INSERT INTO `hilos_file` (`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, `owner_user_id`, `visibility`, `bound`, '
            . '`created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                "photo-{$userId}.jpg",
                'photo.jpg',
                'image/jpeg',
                4,
                hash('sha256', "photo-{$userId}"),
                $userId,
                FileVisibility::PUBLIC->value,
                1,
                self::CREATED_AT,
            ],
        );
        $fileId = Database::lastInsertId();
        Database::sqlRun(
            'INSERT INTO `hilos_user_photo` (`user_id`, `file_id`, `set_at`) VALUES (?, ?, ?)',
            [$userId, $fileId, self::CREATED_AT],
        );

        return $fileId;
    }

    /**
     * @param int $userId Recipient
     * @return int Notification id
     */
    private static function seedNotification(int $userId): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, ?)',
            [$userId, 'account.part', 'Part notification', self::CREATED_AT],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $notificationId Notification delivered by letter
     */
    private static function seedDelivery(int $notificationId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [
                $notificationId,
                ErasurePersonalPartTestLetterChannel::NAME,
                self::ROUTING_KEY,
                DeliveryStatus::PENDING,
                self::CREATED_AT,
                self::CREATED_AT,
            ],
        );
    }

    /**
     * @param int $userId Account folded into another one
     * @param int $survivorId Account it was folded into
     */
    private static function seedMerge(int $userId, int $survivorId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorId, TimeHelper::nowMs()],
        );
    }

    /**
     * @param int $userId Person
     * @param list<string> $sets Tables of the sets counted, all the part deletes unless a case drops one
     * @return array<string, int> Rows of the person in each of those sets
     */
    private static function setsOf(int $userId, array $sets = self::PERSONAL_SETS): array
    {
        $rows = [];
        foreach ($sets as $table) {
            $rows[$table] = (int)Database::sql("SELECT COUNT(*) AS `count` FROM `{$table}` WHERE `user_id` = ?", [$userId])->row()['count'];
        }

        return $rows;
    }

    /**
     * @param int $userId Person
     * @return bool Whether the person's own row is stored
     */
    private static function personExists(int $userId): bool
    {
        return Database::sql('SELECT `id` FROM `hilos_user` WHERE `id` = ?', [$userId])->rows() !== [];
    }

    /**
     * @param int $fileId Registry file
     * @return int Rows of the registry under that id
     */
    private static function fileRows(int $fileId): int
    {
        return count(Database::sql('SELECT `id` FROM `hilos_file` WHERE `id` = ?', [$fileId])->rows());
    }

    /**
     * @return list<array{int, string, string}> Assignment, owner and payload of every stored result of an expected part, in id order
     */
    private static function storedPartResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['owner_agent_id'], (string)$row['payload_json']],
            Database::sql(
                'SELECT `r`.`assignment_id`, `r`.`owner_agent_id`, `r`.`payload_json` FROM `hilos_account_operation_result` `r`'
                    . ' JOIN `hilos_account_operation_assignment` `a` ON `a`.`id` = `r`.`assignment_id`'
                    . ' WHERE `a`.`assignment_key` LIKE ? ORDER BY `r`.`id`',
                ['expect:%'],
            )->rows(),
        );
    }

    /** @return list<list<int>> File ids of every removal queued since the last call, in queue order */
    private static function removedFiles(): array
    {
        $removals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                $removals[] = $signal->data->data->fileIds;
            }
        }

        return $removals;
    }

    /**
     * @param bool $down Drop in reverse dependency order
     * @param list<string> $tables Tables raised or dropped, every one the part reaches unless a case drops one
     */
    private static function stubs(bool $down, array $tables = self::TABLES): void
    {
        foreach ($down ? array_reverse($tables) : $tables as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class ErasurePersonalPartTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_erasure_personal_part_users_library';
}

/** The framework's session holder, writing the account operations of a case. */
final class ErasurePersonalPartTestSessionsHolder extends AbstractSessionsLibraryAgent
{
}

/** The person's agent, telling the case each time it has deleted its child sets. */
final class ErasurePersonalPartTestUserAgent extends AbstractUserAgent
{
    /**
     * @param string $agentIndex Person id from the agent address
     * @param Closure(): void $afterChildren Called right after the child sets are deleted; may throw
     */
    public function __construct(string $agentIndex, private readonly Closure $afterChildren)
    {
        parent::__construct($agentIndex);
    }

    /**
     * @param int $userId Person whose account is being erased
     * @return AccountErasure What the framework's part erased
     */
    protected function erasePersonalChildren(int $userId): AccountErasure
    {
        $erasure = parent::erasePersonalChildren($userId);
        ($this->afterChildren)();

        return $erasure;
    }
}

/**
 * The installation of the case: the person's agent per person, so the census expects the person's
 * part of it; a files registry; and one pooled letter channel whose sender owns its deliveries.
 */
abstract class ErasurePersonalPartTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::FILES];
    protected const string NOTIFICATION_CHANNEL_REGISTRY = ErasurePersonalPartTestChannels::class;

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasurePersonalPartTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        ErasurePersonalPartTestLetterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ErasurePersonalPartTestLetterAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** The letter channel alone. */
abstract class ErasurePersonalPartTestChannels extends DeliveryChannelRegistry
{
    /** @return array<string, AbstractDeliveryChannel> The letter channel by name */
    protected static function channels(): array
    {
        return [ErasurePersonalPartTestLetterChannel::NAME => new ErasurePersonalPartTestLetterChannel()];
    }
}

/** A pooled channel four senders wide, routed by the saved key alone. */
final class ErasurePersonalPartTestLetterChannel extends AbstractDeliveryChannel
{
    public const string NAME = 'part_letter';

    /** @return string Channel name */
    public function name(): string
    {
        return self::NAME;
    }

    /** @return string Delivery signal of its sender */
    public function deliverSignalName(): string
    {
        return ErasurePersonalPartTestLetterAgent::SIGNAL;
    }

    /** @return string Retry signal of its sender */
    public function retrySignalName(): string
    {
        return ErasurePersonalPartTestLetterAgent::SIGNAL . '_retry';
    }

    /**
     * @param int $userId Recipient
     * @return ?string No address: the part never resolves one
     */
    public function resolveAddress(int $userId): ?string
    {
        return null;
    }

    /** @return bool Always pooled */
    public function isPooled(): bool
    {
        return true;
    }

    /** @return int Four senders */
    public function poolSize(): int
    {
        return 4;
    }
}

/** The letter channel's indexed sender. */
final class ErasurePersonalPartTestLetterAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'part_letter_sender';
    public const string SIGNAL = 'part_letter_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}
