<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserStepUpCreditDoneSignalData;
use Hilos\Users\DTO\UserStepUpCreditSignalData;
use Hilos\Users\DTO\UserStepUpRecordDoneSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;

/**
 * A person's step-up confirmations and the trust of their browsers are written by that person's agent (HIL-1407).
 *
 * The users library checks a proof and the sessions library ends sessions, but neither records a
 * confirmation itself any more: the truth source refuses both. The users library takes no trust
 * away either. The same writes handed to the person's agent go through, and the agent answers
 * every frame - a refused one included. A frame naming another person is refused rather than
 * written, and an account folded into another one is credited nothing.
 *
 * The sessions library keeps its claim over every browser's trust whole - it creates one on the way
 * in and applies the days an administrator sets to every person at once - so the truth source does
 * not tell its removal of one person's trust from the agent's; that the agent is the one writer of
 * it is the code's, and the session cases check it where the holder ends sessions.
 *
 * Every writer runs in its own frame and under the claims its own class declares, and the frames
 * between them are carried by the case.
 */
final class PersonStepUpTrustEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-step-up-trust-edits';

    private const string SESSION_TOKEN = 'ab000000000000000000000000001407';

    private const string OTHER_SESSION_TOKEN = 'cd000000000000000000000000001407';

    private const string CREATED_AT = '2026-10-10 10:00:00';

    /** Days a trust is written for and asked about. */
    private const int TRUST_DAYS = 30;

    private StepUpTrustTestUsersLibrary $users;

    private StepUpTrustTestSessionsLibrary $sessions;

    private ?SignalRouter $previousRouter = null;

    /** @var array<string, SignalDTO> Signals the last {@see carry()} took off the queue beside the answer, by name */
    private array $carried = [];

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->users = new StepUpTrustTestUsersLibrary();
        $this->sessions = new StepUpTrustTestSessionsLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb($this->sessions::class, $this->sessions->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->users->getId(), $this->sessions->getId()] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheLibrariesRecordingAConfirmationThemselvesAreRefused(): void
    {
        $userId = $this->seedSignedInPerson();
        $hash = ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);

        foreach ([$this->users, $this->sessions] as $library) {
            try {
                ExecutionContext::run(
                    new ExecutionFrame(agentId: $library->getId()),
                    static fn () => StepUpConfirmations::record($library, $hash, $userId, StepUpOperationKey::CHANGE_PASSWORD),
                );
                self::fail("{$library->getId()} must not record a confirmation past the person's agent");
            } catch (WriteNotAllowedException | CreateNotAllowedException $refusal) {
                self::assertStringContainsString($library->getId(), $refusal->getMessage());
            }
        }

        self::assertFalse(Hilos::$db->stepUps->isConfirmed($hash, $userId, StepUpOperationKey::CHANGE_PASSWORD));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryTakingABrowsersTrustItselfIsRefused(): void
    {
        $userId = $this->seedSignedInPerson();
        $sessionId = $this->trust(self::SESSION_TOKEN, $userId);

        $this->expectException(WriteNotAllowedException::class);
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->users->getId()),
                static fn () => Hilos::$db->secondFactorTrusts->actions->deleteForUser($userId),
            );
        } finally {
            self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, self::TRUST_DAYS));
        }
    }

    /**
     * A password or a code the users library checked is recorded by the agent, which tells every
     * tab of the browser before it answers.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentRecordsAConfirmationAndTellsTheBrowser(): void
    {
        $userId = $this->seedSignedInPerson();
        $hash = ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);

        $answer = $this->carry($this->users, HilosSignalConstants::HILOS_USER_STEP_UP_RECORD, $this->record($userId));

        self::assertInstanceOf(UserStepUpRecordDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed($hash, $userId, StepUpOperationKey::CHANGE_PASSWORD));
        $told = $this->carried[HilosSignalConstants::HILOS_STEP_UP_CONFIRMED] ?? null;
        self::assertNotNull($told, 'The browser hears of the confirmation before the answer');
        self::assertSame(HilosAgentType::HILOS_USER, $told->signalSource->getType());
        self::assertSame((string)$userId, $told->signalSource->getIndex());
    }

    /**
     * A refused sign-in of a blocked person credits the data copy through the agent.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentRecordsACreditForTheDataCopy(): void
    {
        $userId = $this->seedSignedInPerson();
        $hash = ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);

        $answer = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT, new UserStepUpCreditSignalData(
            $userId,
            $hash,
            StepUpOperationKey::EXPORT_DATA,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE,
        ));

        self::assertInstanceOf(UserStepUpCreditDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertTrue(Hilos::$db->stepUps->isConfirmed($hash, $userId, StepUpOperationKey::EXPORT_DATA));
    }

    /**
     * An account folded into another one between the hop and the frame is refused, and nothing is recorded.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testACreditForAFoldedAccountIsRefusedAndRecordsNothing(): void
    {
        $userId = $this->seedSignedInPerson();
        $survivorId = self::seedPerson();
        $hash = ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            static fn () => Hilos::$db->userMerges->actions->add($userId, $survivorId),
        );

        $answer = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT, new UserStepUpCreditSignalData(
            $userId,
            $hash,
            StepUpOperationKey::EXPORT_DATA,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE,
        ));

        self::assertInstanceOf(UserStepUpCreditDoneSignalData::class, $answer);
        self::assertSame(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $answer->error);
        self::assertFalse(Hilos::$db->stepUps->isConfirmed($hash, $userId, StepUpOperationKey::EXPORT_DATA));
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_STEP_UP_CONFIRMED, $this->carried);
    }

    /**
     * Ending one session takes that browser's trust; ending the others takes every one but the kept.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentTakesTheTrustOfEndedSessions(): void
    {
        $userId = $this->seedSignedInPerson();
        self::seedSession(self::OTHER_SESSION_TOKEN, $userId, self::CREATED_AT, null);
        $currentId = $this->trust(self::SESSION_TOKEN, $userId);
        $otherId = $this->trust(self::OTHER_SESSION_TOKEN, $userId);

        $one = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE, new UserBrowserTrustRevokeSignalData(
            $userId,
            $otherId,
            false,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE,
        ));
        self::assertInstanceOf(UserBrowserTrustRevokeDoneSignalData::class, $one);
        self::assertNull($one->error);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentId, $userId, self::TRUST_DAYS));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherId, $userId, self::TRUST_DAYS));

        $this->trust(self::OTHER_SESSION_TOKEN, $userId);
        $others = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE, new UserBrowserTrustRevokeSignalData(
            $userId,
            $currentId,
            true,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE,
        ));
        self::assertInstanceOf(UserBrowserTrustRevokeDoneSignalData::class, $others);
        self::assertNull($others->error);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentId, $userId, self::TRUST_DAYS));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherId, $userId, self::TRUST_DAYS));
    }

    /**
     * A block takes the trust of every browser of the person with it; lifting the block gives none back.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testABlockTakesEveryTrustWithTheFlag(): void
    {
        $userId = $this->seedSignedInPerson();
        $sessionId = $this->trust(self::SESSION_TOKEN, $userId);

        $blocked = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_BLOCK_WRITE, $this->blockWrite($userId, true));
        self::assertInstanceOf(UserBlockWriteDoneSignalData::class, $blocked);
        self::assertNull($blocked->error);
        self::assertTrue(Hilos::$db->users[$userId]?->block);
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, self::TRUST_DAYS));

        $this->trust(self::SESSION_TOKEN, $userId);
        $lifted = $this->carry($this->sessions, HilosSignalConstants::HILOS_USER_BLOCK_WRITE, $this->blockWrite($userId, false));
        self::assertInstanceOf(UserBlockWriteDoneSignalData::class, $lifted);
        self::assertNull($lifted->error);
        self::assertFalse(Hilos::$db->users[$userId]?->block);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, self::TRUST_DAYS));
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = $this->seedSignedInPerson();
        $otherId = self::seedPerson();

        $this->expectException(AgentException::class);
        try {
            $this->asPerson($userId, fn ($agent) => $agent->onSignalAgent(
                new AgentSignalData(data: $this->record($otherId)),
                '',
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD,
            ));
        } finally {
            self::assertFalse(Hilos::$db->stepUps->isConfirmed(
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                $otherId,
                StepUpOperationKey::CHANGE_PASSWORD,
            ));
        }
    }

    /**
     * Sends one frame the way a library does, hands it to the person's agent and takes its answer.
     *
     * Every other signal the agent queued is kept in {@see $carried}, by name.
     *
     * @param AbstractAgent $sender Library the frame leaves from
     * @param string $name Agent-signal name the frame travels under
     * @param UserStepUpRecordSignalData|UserStepUpCreditSignalData|UserBrowserTrustRevokeSignalData|UserBlockWriteSignalData $frame The frame
     * @return mixed Payload of the answer, under the reply name the frame carries
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(
        AbstractAgent $sender,
        string $name,
        UserStepUpRecordSignalData|UserStepUpCreditSignalData|UserBrowserTrustRevokeSignalData|UserBlockWriteSignalData $frame,
    ): mixed {
        // What the fixtures announced on the way in is nobody's business here.
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $sender->sendToAgent($name, $frame);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $this->carried = [];
        $answer = null;
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer === null && $queued->signalName->getName() === $frame->replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answer = $queued->data->data;
            } else {
                $this->carried[$queued->signalName->getName()] ??= $queued;
            }
        }
        self::assertNotNull($answer, "Nothing was sent under {$frame->replySignal}");

        return $answer;
    }

    /**
     * @param int $userId Person who confirmed
     * @return UserStepUpRecordSignalData The ask the users library sends once a password proved the person
     */
    private function record(int $userId): UserStepUpRecordSignalData
    {
        return new UserStepUpRecordSignalData(
            userId: $userId,
            sessionTokenHash: ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            operation: StepUpOperationKey::CHANGE_PASSWORD,
            replySignal: HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            successMessage: null,
        );
    }

    /**
     * @param int $userId Person to block or let back
     * @param bool $block Flag to write
     * @return UserBlockWriteSignalData The ask the sessions library sends from the admin card
     */
    private function blockWrite(int $userId, bool $block): UserBlockWriteSignalData
    {
        return new UserBlockWriteSignalData(
            userId: $userId,
            block: $block,
            by: 1,
            replySignal: HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
            successMessage: null,
            answerSignal: 'block_card_answer',
        );
    }

    /**
     * Trusts the browser of one session for the person, the way a sign-in let through does.
     *
     * @param string $sessionToken Session of the browser
     * @param int $userId Person the browser is trusted for
     * @return int Session row of the browser
     * @throws HilosException When the session cannot be read or the trust cannot be written
     */
    private function trust(string $sessionToken, int $userId): int
    {
        $sessionId = (int)Hilos::$db->sessions->findByToken($sessionToken)?->id;
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            static fn () => Hilos::$db->secondFactorTrusts->actions->trust(
                $sessionId,
                $userId,
                date('Y-m-d H:i:s', time() + self::TRUST_DAYS * TimeConstants::SECONDS_PER_DAY),
            ),
        );
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, self::TRUST_DAYS));

        return $sessionId;
    }

    /**
     * @return int Id of a new person signed in on {@see SESSION_TOKEN}
     * @throws DatabaseException When an insert fails
     */
    private function seedSignedInPerson(): int
    {
        $userId = self::seedPerson();
        self::seedSession(self::SESSION_TOKEN, $userId, self::CREATED_AT, null);

        return $userId;
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class StepUpTrustTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_step_up_trust_users_library';
}

/** The framework's sessions library under a test name, with nothing overridden. */
final class StepUpTrustTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_step_up_trust_sessions_library';
}
