<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

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
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
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

        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE, new AccountDeletionCodeActionDTO());

        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::ACCOUNT_DELETION, self::EMAIL, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame(
            [[self::EMAIL, MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_ACCOUNT_DELETION),
        );
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

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START,
            new AccountDeletionStartActionDTO(self::WRONG_CODE),
        );

        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertSame([], $this->stateFrames());
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
        self::assertCount(1, $frames);
        self::assertSame(
            [
                AccountDeletionStateSignalData::requestedAt => $requestedAt,
                AccountDeletionStateSignalData::effectiveAt => $effectiveAt,
            ],
            $frames[0]->deletion,
        );
    }

    /**
     * A scheduled deletion refuses opening, the code and a second start - another tab started it.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAScheduledDeletionRefusesEveryStepThatLeadsToOne(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
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
        $this->submit(
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO(StepUpOperationKey::DELETE_ACCOUNT, StepUpMethod::PASSWORD, '', false, self::PASSWORD, null),
        );

        $opening = $this->open();
        self::assertNull($opening->channel);
        self::assertNull($opening->destination);

        $this->submit(HilosSignalConstants::HILOS_ACCOUNT_DELETION_START, new AccountDeletionStartActionDTO(''));

        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
        self::assertSame([], $this->mailer->sent);
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
     */
    private function stateFrames(): array
    {
        $states = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
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
