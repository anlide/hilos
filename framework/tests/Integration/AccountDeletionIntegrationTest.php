<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Code\DTO\CodeSendReplyDTO;
use Hilos\Auth\Session\DTO\ProfileFlowsSignalData;
use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionMessages;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCancelActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionCodeActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpenActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionOpeningReplyDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStartActionDTO;
use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStateSignalData;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * A person's own account deletion, run by the framework's users library (HIL-302).
 *
 * Four submits: opening the window, the code, the start and the cancel. The three that lead
 * to a deletion open with the operation's confirmation and read the account again; the cancel
 * asks for nothing but a session that is the person's own. Every start and cancel fans the
 * person's state to their group - the frame the second tab turns its zone by.
 */
final class AccountDeletionIntegrationTest extends ProfileIntegrationTestCase
{
    private const string EMAIL = 'leaving@example.test';
    private const string PASSWORD = 'a-long-enough-secret';
    private const string CODE = '302302';
    private const string WRONG_CODE = '000000';

    /** The grace period the fixture catalog defaults to, in days. */
    private const int GRACE_DAYS = 30;

    /** Slack between the moment a case computes and the moment the command stamped, in seconds. */
    private const int CLOCK_SLACK_SEC = 5;

    private string $previousAppClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        ProfileIntegrationAdminHilos::initBrowser();
        ProfileIntegrationAdminAudience::$ids = [self::ADMIN_USER_ID];
    }

    protected function tearDown(): void
    {
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    public function testAdministratorSchedulesTheSameGraceAndPublishesThePersonsState(): void
    {
        $this->confirmAdministrator();
        $reply = $this->adminDeletion(self::USER_ID, true);
        self::assertNull($reply->error);
        self::assertSame('Deletion scheduled: the account is erased in 30 days', $reply->successMessage);
        $deletion = Hilos::$db->accountDeletions->liveOf(self::USER_ID);
        self::assertNotNull($deletion);
        self::assertEqualsWithDelta(
            self::GRACE_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            TimeHelper::sqlToMs($deletion->effectiveAt) - TimeHelper::sqlToMs($deletion->requestedAt),
            self::CLOCK_SLACK_SEC * TimeConstants::MS_PER_SECOND,
        );
        $frames = $this->stateFrames();
        self::assertCount(1, $frames);
        self::assertSame(TimeHelper::sqlToMs($deletion->effectiveAt), $frames[0]->deletion['effectiveAt']);
        self::assertSame(
            'account_deletion_scheduled ' . json_encode(['event' => 'account_deletion_scheduled',
                'user' => self::USER_ID, 'by' => self::ADMIN_USER_ID]),
            $this->library->messages[0],
        );
    }

    public function testAdministratorCanCancelAndThePersonCanCancelAnAdministratorsRequest(): void
    {
        $this->confirmAdministrator();
        $this->adminDeletion(self::USER_ID, true);
        $this->stateFrames();
        self::assertNull($this->adminDeletion(self::USER_ID, false)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertNull($this->stateFrames()[0]->deletion);
        self::assertStringStartsWith('account_deletion_canceled ', $this->library->messages[1]);

        $this->adminDeletion(self::USER_ID, true);
        $this->stateFrames();
        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL, new AccountDeletionCancelActionDTO());
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertNull($this->stateFrames()[0]->deletion);
    }

    public function testAdministratorCannotScheduleSelfAnotherAdministratorOrAnExistingRequest(): void
    {
        $this->confirmAdministrator();
        self::assertSame('Delete your own account from your profile', $this->adminDeletion(self::ADMIN_USER_ID, true)->error);
        ProfileIntegrationAdminAudience::$ids[] = self::USER_ID;
        self::assertSame('Remove the admin rights first', $this->adminDeletion(self::USER_ID, true)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        ProfileIntegrationAdminAudience::$ids = [self::ADMIN_USER_ID];
        $this->adminDeletion(self::USER_ID, true);
        $this->stateFrames();
        self::assertSame(AccountDeletionMessages::ALREADY_SCHEDULED, $this->adminDeletion(self::USER_ID, true)->error);
        self::assertSame([], $this->stateFrames());
    }

    public function testAdministratorCancellationWithoutARequestIsRefusedButCancelingSelfIsAllowed(): void
    {
        self::assertSame(AccountDeletionMessages::NOTHING_SCHEDULED, $this->adminDeletion(self::USER_ID, false)->error);
        Hilos::$db->accountDeletions->actions->request(self::ADMIN_USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
        self::assertNull($this->adminDeletion(self::ADMIN_USER_ID, false)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::ADMIN_USER_ID));
    }

    public function testOrdinaryAndAnonymousConnectionsCannotScheduleOrCancel(): void
    {
        $this->confirmAdministrator();
        self::assertSame(
            'Only an active administrator can do this',
            $this->adminDeletion(self::OTHER_USER_ID, true, self::ACCEPT_KEY)->error,
        );
        self::assertSame('User session not found', $this->adminDeletion(self::USER_ID, true, self::ANONYMOUS_ACCEPT_KEY)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::OTHER_USER_ID));
        $this->adminDeletion(self::USER_ID, true);
        self::assertSame(
            'Only an active administrator can do this',
            $this->adminDeletion(self::USER_ID, false, self::ACCEPT_KEY)->error,
        );
        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
    }

    /**
     * Scheduling someone else's deletion asks the administrator's fresh confirmation of exactly
     * that operation, and without one nothing is scheduled (HIL-1275).
     */
    public function testAdministratorSchedulesOnlyWithAFreshConfirmationAndCancelsWithout(): void
    {
        self::assertSame(StepUpMessages::EXPIRED, $this->adminDeletion(self::USER_ID, true)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertSame([], $this->stateFrames());

        $this->confirmStepUp(StepUpOperationKey::DELETE_ACCOUNT, self::ADMIN_SESSION_TOKEN, self::ADMIN_USER_ID);
        self::assertSame(
            StepUpMessages::EXPIRED,
            $this->adminDeletion(self::USER_ID, true)->error,
            "The person's own deletion is another operation",
        );

        $this->confirmAdministrator();
        self::assertNull($this->adminDeletion(self::USER_ID, true)->error);
        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
    }

    /**
     * Calling a deletion off gives back rather than takes away: no confirmation is asked (HIL-1275).
     */
    public function testAdministratorCancelsWithoutAConfirmation(): void
    {
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));

        self::assertNull($this->adminDeletion(self::USER_ID, false)->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
    }

    /**
     * Seeds the administrator's live confirmation of deleting someone else's account, in their own browser.
     *
     * @throws HilosException When the confirmation row cannot be written
     */
    private function confirmAdministrator(): void
    {
        $this->confirmStepUp(StepUpOperationKey::DELETE_OTHER_ACCOUNT, self::ADMIN_SESSION_TOKEN, self::ADMIN_USER_ID);
    }

    private function adminDeletion(int $userId, bool $scheduled, string $acceptKey = self::ADMIN_ACCEPT_KEY): HandoverAnswerSignalData
    {
        $this->library->onSignalAgent(new AgentSignalData(new AccountDeletionSetSignalData(
            $userId, $scheduled, HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
            $acceptKey, 'admin-deletion', HilosSignalConstants::HILOS_USER_DELETION_SET, null,
        )), 'page', HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET);
        $other = [];
        $answer = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                self::assertNull($answer);
                $answer = $signal->data->data;
                self::assertSame('admin-deletion', $answer->requestId);
            } else {
                $other[] = $signal;
            }
        }
        foreach ($other as $signal) {
            Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        self::assertNotNull($answer);

        return $answer;
    }

    /**
     * A password account must freshly confirm the operation before the window opens.
     *
     * @throws HilosException When the seed fails
     */
    public function testOpeningRefusesAPasswordAccountWithoutAConfirmation(): void
    {
        $this->seedPasswordAccount();

        $this->assertOpeningRefused(StepUpMessages::EXPIRED);
    }

    /**
     * An account whose only proof is its address opens straight away, with the code's address.
     *
     * No double code (HIL-495): the operation confirms itself with a code to the same mailbox.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAnAddressOnlyAccountOpensWithTheGracePeriodAndTheAddress(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);

        $opening = $this->open();

        self::assertSame(self::GRACE_DAYS, $opening->graceDays);
        self::assertSame(AccountDeletionOpeningReplyDTO::CHANNEL_EMAIL, $opening->channel);
        self::assertSame(self::EMAIL, $opening->destination);
    }

    /**
     * The code goes to the account's address as a deletion code, with its own letter.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testTheCodeIsMailedAsADeletionCode(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);

        $reply = $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE, new AccountDeletionCodeActionDTO());
        $this->settleProfileFlows();
        self::assertInstanceOf(CodeSendReplyDTO::class, $reply);
        self::assertTrue($reply->sent);
        self::assertSame(StepUpOperationKey::DELETE_ACCOUNT, $this->codeSendLine()?->purpose);
        self::assertSame($reply->resendAt, $this->codeSendLine()?->resendAt);

        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame(
            [[self::EMAIL, MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION),
        );
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
        $flow = $this->profileFlows()[HilosProfileFlow::idFor(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            StepUpOperationKey::DELETE_ACCOUNT,
        )];
        self::assertNotNull($flow);
        self::assertSame(self::EMAIL, $flow->address);
        self::assertSame($reply->expiresAt, $flow->expiresAt);
        self::assertSame(null, $flow->target);
        self::assertSame([[
            HilosProfileFlow::operation => StepUpOperationKey::DELETE_ACCOUNT,
            HilosProfileFlow::step => HilosProfileFlow::STEP_CODE_SENT,
            HilosProfileFlow::address => self::EMAIL,
            HilosProfileFlow::target => null,
        ]], $this->profileFlowFrames()[0]->flows);
    }

    /** @throws HilosException When the code or session flow cannot be written */
    public function testCooldownKeepsTheLiveCodeMomentInTheSessionFlow(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $sent = $this->submitStep(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE, new AccountDeletionCodeActionDTO());
        $held = $this->submitStep(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE, new AccountDeletionCodeActionDTO());

        self::assertInstanceOf(CodeSendReplyDTO::class, $sent);
        self::assertInstanceOf(CodeSendReplyDTO::class, $held);
        self::assertTrue($sent->sent);
        self::assertFalse($held->sent);
        self::assertSame($sent->expiresAt, $held->expiresAt);
        $flow = $this->profileFlows()[HilosProfileFlow::idFor(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            StepUpOperationKey::DELETE_ACCOUNT,
        )];
        self::assertSame($sent->expiresAt, $flow?->expiresAt);
        self::assertSame(2, count($this->profileFlowFrames()));
    }

    /** @throws HilosException When the verification history or action cannot be written */
    public function testSendCapDoesNotCreateADeletionFlow(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $key = EnvConstants::HILOS_VERIFICATION_SEND_CAP->name;
        $previous = getenv($key);
        putenv($key . '=1');
        try {
            new VerificationService()->issue(
                VerificationType::ACCOUNT_DELETION,
                self::EMAIL,
                self::USER_ID,
            );
            $cooldown = Hilos::$env[EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC]->int();
            $this->verifications()->backdateIdentifier(self::EMAIL, date('Y-m-d H:i:s', time() - $cooldown - 1));

            $this->assertRefused(
                AuthMessages::SEND_CAP,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE,
                new AccountDeletionCodeActionDTO(),
            );
            $this->settleProfileFlows();

            self::assertNull($this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
            self::assertSame([], $this->profileFlowFrames());
        } finally {
            putenv($previous === false ? $key : $key . '=' . $previous);
        }
    }

    /**
     * A wrong code starts nothing.
     *
     * @throws HilosException When the seed fails
     */
    public function testAWrongCodeStartsNothing(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->seedCode(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedFlow(StepUpOperationKey::DELETE_ACCOUNT, HilosProfileFlow::STEP_CODE_SENT,
            VerificationType::ACCOUNT_DELETION, self::EMAIL);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
            new AccountDeletionStartActionDTO(self::WRONG_CODE),
        );

        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertSame([], $this->stateFrames());
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
    }

    /**
     * The right code schedules the erasure a grace period away and tells every tab.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testTheRightCodeSchedulesTheDeletionAndTellsTheGroup(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->seedCode(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedFlow(StepUpOperationKey::DELETE_ACCOUNT, HilosProfileFlow::STEP_CODE_SENT,
            VerificationType::ACCOUNT_DELETION, self::EMAIL);
        $before = time();

        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_START, new AccountDeletionStartActionDTO(self::CODE));

        $deletion = Hilos::$db->accountDeletions->liveOf(self::USER_ID);
        self::assertNotNull($deletion);
        $requestedAt = TimeHelper::sqlToMs($deletion->requestedAt);
        $effectiveAt = TimeHelper::sqlToMs($deletion->effectiveAt);
        self::assertEqualsWithDelta(
            self::GRACE_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            $effectiveAt - $requestedAt,
            self::CLOCK_SLACK_SEC * TimeConstants::MS_PER_SECOND,
        );
        self::assertGreaterThanOrEqual(($before - self::CLOCK_SLACK_SEC) * TimeConstants::MS_PER_SECOND, $requestedAt);
        self::assertNull($this->verifications()->findActive(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::MAX_ATTEMPTS));

        $frames = $this->stateFrames();
        self::assertNull($this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
        self::assertCount(1, $frames);
        self::assertSame(
            [
                AccountDeletionStateSignalData::requestedAt => $requestedAt,
                AccountDeletionStateSignalData::effectiveAt => $effectiveAt,
            ],
            $frames[0]->deletion,
        );
    }

    /** @throws HilosException When the code or session flow cannot be written */
    public function testStartRemovesTheSessionFlowAndPublishesTheEmptyList(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->seedCode(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedFlow(StepUpOperationKey::DELETE_ACCOUNT, HilosProfileFlow::STEP_CODE_SENT,
            VerificationType::ACCOUNT_DELETION, self::EMAIL);

        $this->submitStep(HilosSignalConstants::HILOS_ACCOUNT_DELETION_START, new AccountDeletionStartActionDTO(self::CODE));

        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertNull($this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
        self::assertSame([], $this->profileFlowFrames()[0]->flows);
    }

    /**
     * A scheduled deletion refuses opening, the code and a second start - another tab started it.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAScheduledDeletionRefusesEveryStepThatLeadsToOne(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->seedCode(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedFlow(StepUpOperationKey::DELETE_ACCOUNT, HilosProfileFlow::STEP_CODE_SENT,
            VerificationType::ACCOUNT_DELETION, self::EMAIL);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));

        $this->assertOpeningRefused(AccountDeletionMessages::ALREADY_SCHEDULED);
        $this->assertRefused(
            AccountDeletionMessages::ALREADY_SCHEDULED,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE,
            new AccountDeletionCodeActionDTO(),
            self::OTHER_ACCEPT_KEY,
        );
        $this->assertRefused(
            AccountDeletionMessages::ALREADY_SCHEDULED,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
            new AccountDeletionStartActionDTO(self::CODE),
        );
        self::assertSame([], $this->mailer->sent);
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::DELETE_ACCOUNT));
    }

    /**
     * Calling it off keeps the account, and every tab learns of it.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testCallingItOffEndsTheRequestAndTellsTheGroup(): void
    {
        $this->seedPasswordAccount();
        $request = Hilos::$db->accountDeletions->actions->request(
            self::USER_ID,
            date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY),
        );

        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL, new AccountDeletionCancelActionDTO());

        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertNotNull($request->canceledAt);
        self::assertNull($request->completedAt);
        $frames = $this->stateFrames();
        self::assertCount(1, $frames);
        self::assertNull($frames[0]->deletion);
    }

    /**
     * Calling off what another tab already called off is a silent success.
     *
     * @throws HilosException When the command fails
     */
    public function testCallingOffNothingIsASilentSuccess(): void
    {
        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL, new AccountDeletionCancelActionDTO());

        self::assertSame([], $this->stateFrames());
    }

    /**
     * Nobody calls off a deletion in an account they only work in on somebody else's behalf.
     *
     * @throws HilosException When the seed or the session update fails
     */
    public function testCallingItOffIsRefusedUnderImpersonation(): void
    {
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
        Database::sqlRun(
            'UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::OTHER_USER_ID, self::SESSION_TOKEN],
        );

        $this->assertRefused(
            StepUpMessages::IMPERSONATED,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            new AccountDeletionCancelActionDTO(),
        );

        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
    }

    /**
     * An account no code can reach starts from the first step, without a code.
     *
     * The password is the proof here: the installation cannot send letters, so the address
     * behind the password is no address for a code.
     *
     * @throws HilosException When the seed, the confirmation or the command fails
     */
    public function testAnAccountNoCodeCanReachStartsWithoutACode(): void
    {
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        $this->seedPasswordAccount();
        $this->submitStep(
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO(StepUpOperationKey::DELETE_ACCOUNT, StepUpMethod::PASSWORD, '', false, self::PASSWORD, null),
        );

        $opening = $this->open();
        self::assertNull($opening->channel);
        self::assertNull($opening->destination);

        $this->submitStep(HilosSignalConstants::HILOS_ACCOUNT_DELETION_START, new AccountDeletionStartActionDTO(''));

        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->profileFlowFrames()[0]->flows);
    }

    /**
     * Opens the window on behalf of the first tab.
     *
     * @return AccountDeletionOpeningReplyDTO The opening answer
     * @throws HilosException When the command refuses or fails
     */
    private function open(): AccountDeletionOpeningReplyDTO
    {
        $reply = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN,
            new AccountDeletionOpenActionDTO(),
        );
        self::assertInstanceOf(AccountDeletionOpeningReplyDTO::class, $reply);

        return $reply;
    }

    /**
     * Asserts that opening the window is refused with exactly the given sentence.
     *
     * @param string $message Expected refusal
     * @throws HilosException When the command fails for another reason
     */
    private function assertOpeningRefused(string $message): void
    {
        try {
            $this->open();
        } catch (ValidationException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail("Opening must be refused with: {$message}");
    }

    /**
     * Drains the queue and returns every deletion-state frame sent to the person's group.
     *
     * @return list<AccountDeletionStateSignalData> The states, in order
     * @throws HilosException When a queued flow step cannot be settled
     */
    private function stateFrames(): array
    {
        $states = [];
        foreach ($this->drainSignals() as $signal) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(AccountDeletionGroup::forUser(self::USER_ID), $signal->data->targetGroup);
            self::assertInstanceOf(AccountDeletionStateSignalData::class, $signal->data->data);
            $states[] = $signal->data->data;
        }

        return $states;
    }

    /**
     * @return list<ProfileFlowsSignalData> Session lists published after deletion flow steps
     * @throws HilosException When a queued flow step cannot be settled
     */
    private function profileFlowFrames(): array
    {
        $frames = [];
        foreach ($this->drainSignals() as $signal) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_PROFILE_FLOWS) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN), $signal->data->targetSessionTokenHash);
            self::assertInstanceOf(ProfileFlowsSignalData::class, $signal->data->data);
            $frames[] = $signal->data->data;
        }

        return $frames;
    }

    /**
     * Seeds an account signed in by password on its confirmed address.
     *
     * @throws HilosException When an identity cannot be written
     */
    private function seedPasswordAccount(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        Hilos::$db->identities->createPasswordIdentity(self::USER_ID, self::EMAIL, self::PASSWORD)->markVerified();
    }
}
