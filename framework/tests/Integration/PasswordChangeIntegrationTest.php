<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Code\DTO\CodeSendReplyDTO;
use Hilos\Auth\Verification\VerificationEndPauseCommandConstants;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\Exception\PasswordTooCommonException;
use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\AuthOtherSessionsEndSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustRevokeOthersSignalData;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpenActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpeningReplyDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Existing-password change through the users library, including proof order and all-address recovery invalidation.
 *
 * The proof the final submit stands on is the session's record of a matched code (HIL-1182), written through the
 * session holder by the step that matched it - the tab no longer sends the code with the new password.
 */
final class PasswordChangeIntegrationTest extends ProfileIntegrationTestCase
{
    private const string EMAIL = 'password-change@example.test';
    private const string SECOND_EMAIL = 'password-change-other@example.test';
    private const string FOREIGN_EMAIL = 'someone-else@example.test';
    private const string PHONE = '+15551234300';
    private const string PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'a-brand-new-secret';
    private const string CODE = '300300';

    /** How much later than the first a newer code of the same address dies. */
    private const int NEWER_CODE_LATER_BY_SECONDS = 60;

    /**
     * Restores the framework facade after a case selecting the phone channel.
     *
     * @throws HilosException When dropping fixture tables fails
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /** @throws HilosException When the seed or command fails */
    public function testEveryActionRequiresConfirmationBeforeItsPasswordOrCodeChecks(): void
    {
        $this->seedPassword();
        $this->refuseEveryAction(StepUpMessages::EXPIRED);
        self::assertSame([], $this->mailer->sent);
    }

    /** @throws HilosException When the seed or command fails */
    public function testEveryActionRefusesImpersonationFirst(): void
    {
        Database::sqlRun(
            'UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::OTHER_USER_ID, self::SESSION_TOKEN],
        );
        $this->refuseEveryAction(StepUpMessages::IMPERSONATED);
    }

    /** @throws HilosException When the seed or command fails */
    public function testEveryActionRefusesAnAccountWithoutAPassword(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $this->refuseEveryAction(AuthMessages::NO_PASSWORD);
    }

    /** @throws HilosException When the seed or command fails */
    public function testOpeningNamesTheFullConfirmedEmailAndPrefersItToThePhone(): void
    {
        $this->prepareChange();
        self::seedIdentity(self::USER_ID, IdentityType::SMS, self::PHONE);
        self::assertSame(['channel' => 'email', 'destination' => self::EMAIL], $this->open()->toArray());
    }

    /** @throws HilosException When the seed or command fails */
    public function testOpeningUsesTheConfirmedPhoneWhenMailCannotGoOut(): void
    {
        $this->prepareChange();
        self::seedIdentity(self::USER_ID, IdentityType::SMS, self::PHONE);
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        PasswordChangeIntegrationHilos::initBrowser();
        self::assertSame(['channel' => 'phone', 'destination' => self::PHONE], $this->open()->toArray());
        $this->submit(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::PASSWORD_CHANGE_SMS, self::PHONE, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame([], $this->mailer->sent);
    }

    /** @throws HilosException When the seed or command fails */
    public function testCodeRequestMailsItsOwnTypeAndCooldownDoesNotSendTwice(): void
    {
        $this->prepareChange();
        $sent = $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        self::assertInstanceOf(CodeSendReplyDTO::class, $sent);
        self::assertTrue($sent->sent);
        self::assertSame(StepUpOperationKey::CHANGE_PASSWORD, $this->codeSendLine()?->purpose);
        self::assertSame($sent->resendAt, $this->codeSendLine()?->resendAt);
        $held = $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        self::assertInstanceOf(CodeSendReplyDTO::class, $held);
        self::assertFalse($held->sent);
        self::assertNotNull($held->expiresAt);
        self::assertSame(HilosCodeSendAttempt::STATE_SENT, $this->codeSendLine()?->state);
        self::assertSame(HilosCodeSendAttempt::REASON_RATE_LIMITED, $this->codeSendLine()?->reason);
        self::assertSame($held->resendAt, $this->codeSendLine()?->resendAt);
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));
        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame(
            [[self::EMAIL, MailTemplateCatalogConstants::AUTH_PASSWORD_CHANGE]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_PASSWORD_CHANGE),
        );
    }

    /** @throws HilosException When the code or session line cannot be written */
    public function testCooldownAfterTheCodeWasSpentReportsNoLiveCode(): void
    {
        $this->prepareChange();
        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        $this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS)?->consume();

        $reply = $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());

        self::assertInstanceOf(CodeSendReplyDTO::class, $reply);
        self::assertFalse($reply->sent);
        self::assertNull($reply->expiresAt);
        self::assertSame(HilosCodeSendAttempt::STATE_HELD, $this->codeSendLine()?->state);
        self::assertSame(HilosCodeSendAttempt::REASON_RATE_LIMITED, $this->codeSendLine()?->reason);
        self::assertSame($reply->resendAt, $this->codeSendLine()?->resendAt);
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));
    }

    /** @throws HilosException When the code request or command cannot be completed */
    public function testEndPauseCommandMovesTheLineAndAllowsAnotherSend(): void
    {
        $this->prepareChange();
        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        $this->drainSignals();

        $this->library->onSignalCommand(new CommandRequestDTO(
            'end-pause',
            CliCommands::VERIFICATION_TEST_END_PAUSE,
            [
                VerificationEndPauseCommandConstants::FIELD_ADDRESS => self::EMAIL,
                VerificationEndPauseCommandConstants::FIELD_SESSION_TOKEN => self::SESSION_TOKEN,
            ],
        ), '', '');

        $replies = [];
        foreach ($this->drainSignals() as $signal) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies);
        self::assertTrue($replies[0]->isOk());
        self::assertSame(self::EMAIL, $replies[0]->payload[VerificationEndPauseCommandConstants::FIELD_ADDRESS]);
        self::assertGreaterThanOrEqual(1, $replies[0]->payload[VerificationEndPauseCommandConstants::FIELD_AGED]);
        self::assertNotNull($this->codeSendLine()?->resendAt);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), $this->codeSendLine()?->resendAt);

        $reply = $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        self::assertInstanceOf(CodeSendReplyDTO::class, $reply);
        self::assertTrue($reply->sent);
        self::assertCount(2, $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_PASSWORD_CHANGE));
    }

    /** @throws HilosException When the seed or command fails */
    public function testCodeConfirmationCostsAWrongAttemptButKeepsTheRightCodeAlive(): void
    {
        $this->prepareChange(withCode: true);
        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
            new ProfileChangePasswordCodeConfirmActionDTO('000000'),
        );
        self::assertSame(
            1,
            $this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS)?->attempts,
        );
        $this->submit(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM, new ProfileChangePasswordCodeConfirmActionDTO(self::CODE));
        self::assertNotNull($this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS));
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
    }

    /** @throws HilosException When the seed or command fails */
    public function testAttemptCeilingRefusesEvenTheRightCodeAfterEnoughWrongGuesses(): void
    {
        $this->prepareChange(withCode: true);
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $this->assertRefused(
                AuthMessages::INVALID_CODE,
                HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
                new ProfileChangePasswordCodeConfirmActionDTO('000000'),
            );
        }
        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
            new ProfileChangePasswordCodeConfirmActionDTO(self::CODE),
        );
        self::assertNull($this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS));
    }

    /**
     * Every way the proof can be missing is answered before password policy: no record, a live code
     * that never matched in this session, a record of a code only sent, and a proven record whose
     * code then died, was spent elsewhere, or was replaced by a newer code of the same address.
     *
     * @throws HilosException When the seed or command fails
     */
    public function testSaveRefusesMissingWrongExpiredAndSpentProofBeforePasswordPolicy(): void
    {
        $this->prepareChange();
        $this->refuseSave(self::PASSWORD);
        $this->seedCode(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::USER_ID, self::CODE);
        $this->refuseSave('short');
        $this->seedFlow(StepUpOperationKey::CHANGE_PASSWORD, HilosProfileFlow::STEP_CODE_SENT, VerificationType::PASSWORD_CHANGE, self::EMAIL);
        $this->refuseSave('short');
        $this->seedProvenCode();
        $this->verifications()->expireActive(VerificationType::PASSWORD_CHANGE, self::EMAIL);
        $this->refuseSave('12345678');
        $this->seedProvenCode();
        $this->verifications()->voidActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS);
        $this->refuseSave(self::PASSWORD);
        $this->seedProvenCode();
        $this->verifications()->voidActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS);
        $this->verifications()->createChallenge(
            VerificationType::PASSWORD_CHANGE,
            self::EMAIL,
            self::USER_ID,
            self::CODE,
            self::TTL_SECONDS + self::NEWER_CODE_LATER_BY_SECONDS,
        );
        $this->refuseSave(self::NEW_PASSWORD);
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        self::assertSame([], $this->passwordUpdates());
    }

    /** @throws HilosException When the seed or command fails */
    public function testPasswordPolicyRefusalsLeaveProofAndSecretIntact(): void
    {
        $this->prepareChange(withCode: true);
        foreach ([['short', ValueTooShortException::class], ['12345678', PasswordTooCommonException::class],
            [self::PASSWORD, PasswordUnchangedException::class]] as [$password, $exceptionClass]) {
            try {
                $this->submit(
                    HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
                    new ProfileChangePasswordActionDTO($password, true),
                );
                self::fail('Password policy must refuse the proposed secret');
            } catch (ValidationException $exception) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
            self::assertNotNull($this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS));
        }
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        self::assertSame([], $this->passwordUpdates());
        self::assertSame(HilosProfileFlow::STEP_CODE_PROVEN, $this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));
        $this->submitStep(
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            new ProfileChangePasswordActionDTO(self::NEW_PASSWORD, false),
        );
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::NEW_PASSWORD));
        self::assertNull($this->flowStep(StepUpOperationKey::CHANGE_PASSWORD), 'The finished flow leaves no record');
    }

    /** @throws HilosException When the seed or command fails */
    public function testSaveSpendsProofInvalidatesEveryResetAndAnnouncesTheSessionChoice(): void
    {
        $this->prepareChange(withCode: true);
        $this->seedCode(VerificationType::PASSWORD_RESET, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedCode(VerificationType::PASSWORD_RESET, self::SECOND_EMAIL, self::USER_ID, self::CODE);
        $this->seedCode(VerificationType::PASSWORD_RESET, self::FOREIGN_EMAIL, self::OTHER_USER_ID, self::CODE);
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::EMAIL, self::USER_ID, self::CODE);
        $this->submit(
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            new ProfileChangePasswordActionDTO(self::NEW_PASSWORD, true),
        );
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::NEW_PASSWORD));
        self::assertFalse(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        self::assertNull($this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS));
        foreach ([self::EMAIL, self::SECOND_EMAIL] as $email) {
            self::assertNull($this->verifications()->findActive(VerificationType::PASSWORD_RESET, $email, self::MAX_ATTEMPTS));
        }
        self::assertNotNull($this->verifications()->findActive(VerificationType::PASSWORD_RESET, self::FOREIGN_EMAIL, self::MAX_ATTEMPTS));
        self::assertNotNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::EMAIL, self::MAX_ATTEMPTS));
        $this->assertChangeFrames(signOutOthers: true);
        self::assertNull($this->flowStep(StepUpOperationKey::CHANGE_PASSWORD), 'The finished flow leaves no record');
        $this->refuseSave(self::PASSWORD);
    }

    /** @throws HilosException When the seed or command fails */
    public function testUncheckedSessionChoiceSendsNoSessionEndFrame(): void
    {
        $this->prepareChange(withCode: true);
        $this->submit(
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            new ProfileChangePasswordActionDTO(self::NEW_PASSWORD, false),
        );
        $this->assertChangeFrames(signOutOthers: false);
        self::assertSame(self::USER_ID, Hilos::$db->sessions->findByToken(self::OTHER_SESSION_TOKEN)?->userId);
    }

    /** @throws HilosException When the seed or command fails */
    public function testNoReachableAddressAllowsTheChangeAfterPasswordConfirmation(): void
    {
        $this->prepareChange();
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        self::assertSame(['channel' => null, 'destination' => null], $this->open()->toArray());
        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST,
            new ProfileChangePasswordCodeRequestActionDTO(),
        );
        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
            new ProfileChangePasswordCodeConfirmActionDTO(self::CODE),
        );
        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD, new ProfileChangePasswordActionDTO(self::NEW_PASSWORD, false));
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::NEW_PASSWORD));
        self::assertSame(0, count($this->profileFlows()), 'No code, no proof, no record');
    }

    /**
     * The three steps walked through the session's record, start to finish.
     *
     * @throws HilosException When the seed or a command fails
     */
    public function testTheStepsWalkThroughTheSessionsRecord(): void
    {
        $this->prepareChange();
        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO());
        self::assertSame(HilosProfileFlow::STEP_CODE_SENT, $this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));

        // The letter's code is not readable here, so the case puts one it knows in its place.
        $this->verifications()->voidActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS);
        $this->seedCode(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::USER_ID, self::CODE);
        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM, new ProfileChangePasswordCodeConfirmActionDTO(self::CODE));
        self::assertSame(HilosProfileFlow::STEP_CODE_PROVEN, $this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));

        $this->submitStep(HilosSignalConstants::PROFILE_CHANGE_PASSWORD, new ProfileChangePasswordActionDTO(self::NEW_PASSWORD, false));

        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::NEW_PASSWORD));
        self::assertNull($this->flowStep(StepUpOperationKey::CHANGE_PASSWORD));
        self::assertNull(
            $this->verifications()->findActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS),
            'The proof is spent by the save',
        );
    }

    /** @throws HilosException When the seed or command fails */
    public function testDisabledStepUpDoesNotDisableTheOperationsOwnCode(): void
    {
        $this->seedPassword();
        Hilos::$setting = new SettingsAccessor(PasswordChangeDisabledSettingsCatalog::class);
        self::assertSame('email', $this->open()->channel);
        $this->refuseSave(self::NEW_PASSWORD);
    }

    /** The guessing actions are authenticated and throttled; opening and sending guess nothing. */
    public function testActionGuardsCoverAllFourNames(): void
    {
        foreach ($this->actions() as $dto) {
            self::assertContains($dto->getAction(), AbstractUsersLibraryAgent::AUTH_ACTIONS);
        }
        self::assertContains(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM, AbstractUsersLibraryAgent::THROTTLED_ACTIONS);
        self::assertContains(HilosSignalConstants::PROFILE_CHANGE_PASSWORD, AbstractUsersLibraryAgent::THROTTLED_ACTIONS);
    }

    /**
     * @param bool $withCode Whether to seed a live address code and the session's record that it matched
     * @throws HilosException When a seed or operation confirmation fails
     */
    private function prepareChange(bool $withCode = false): void
    {
        $this->seedPassword();
        $this->submit(
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO(StepUpOperationKey::CHANGE_PASSWORD, StepUpMethod::PASSWORD, '', false, self::PASSWORD, null),
        );
        if ($withCode) {
            $this->seedProvenCode();
        }
    }

    /**
     * Seeds the one live address code and the session's record that it matched.
     *
     * Whatever code was live before is voided first, so the record stands on this one alone - an
     * older code left alive would die at the same second and keep a case's proof standing.
     *
     * @throws HilosException When the challenge writes or the record write fail
     */
    private function seedProvenCode(): void
    {
        $this->verifications()->voidActive(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::MAX_ATTEMPTS);
        $this->seedCode(VerificationType::PASSWORD_CHANGE, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedFlow(StepUpOperationKey::CHANGE_PASSWORD, HilosProfileFlow::STEP_CODE_PROVEN, VerificationType::PASSWORD_CHANGE, self::EMAIL);
    }

    /** @throws HilosException When the password identity cannot be written */
    private function seedPassword(): void
    {
        Hilos::$db->identities->createPasswordIdentity(self::USER_ID, self::EMAIL, self::PASSWORD)->markVerified();
    }

    /**
     * @return ProfileChangePasswordOpeningReplyDTO Opening destination
     * @throws HilosException When opening is refused
     */
    private function open(): ProfileChangePasswordOpeningReplyDTO
    {
        $reply = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN,
            new ProfileChangePasswordOpenActionDTO(),
        );
        self::assertInstanceOf(ProfileChangePasswordOpeningReplyDTO::class, $reply);

        return $reply;
    }

    /** @return list<ActionPayloadDTO> Four action payloads at the browser boundary */
    private function actions(): array
    {
        return [
            new ProfileChangePasswordOpenActionDTO(),
            new ProfileChangePasswordCodeRequestActionDTO(),
            new ProfileChangePasswordCodeConfirmActionDTO(self::CODE),
            new ProfileChangePasswordActionDTO('short', true),
        ];
    }

    /**
     * @param string $message Expected refusal before any step does its own work
     * @throws HilosException When a command fails unexpectedly
     */
    private function refuseEveryAction(string $message): void
    {
        foreach ($this->actions() as $dto) {
            try {
                $this->library->onAgentAction(self::ACCEPT_KEY, $dto->getAction(), $dto);
                self::fail($dto->getAction() . ' must be refused');
            } catch (ValidationException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /**
     * @param string $password Proposed password
     * @throws HilosException When the command fails unexpectedly
     */
    private function refuseSave(string $password): void
    {
        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            new ProfileChangePasswordActionDTO($password, true),
        );
    }

    /**
     * @param bool $signOutOthers Whether a session-ending frame must follow the two tab announcements
     * @throws HilosException When the holder fails to write a step on the way
     */
    private function assertChangeFrames(bool $signOutOthers): void
    {
        $updates = [];
        $ends = [];
        $revokes = [];
        foreach ($this->drainSignals() as $signal) {
            if ($signal->signalName->getName() === HilosSignalConstants::PROFILE_PASSWORD_UPDATED) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(ProfilePasswordUpdatedSignalData::class, $signal->data->data);
                $updates[] = [$signal->data->targetAcceptKey, $signal->data->data->mode];
            }
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(AuthOtherSessionsEndSignalData::class, $signal->data->data);
                $ends[] = $signal->data->data->toArray();
            }
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_REVOKE_OTHERS) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(AuthSecondFactorTrustRevokeOthersSignalData::class, $signal->data->data);
                $revokes[] = $signal->data->data->toArray();
            }
        }
        self::assertSame([[self::ACCEPT_KEY, 'changed'], [self::OTHER_ACCEPT_KEY, 'changed']], $updates);
        self::assertSame(
            $signOutOthers
                ? [[
                    'userId' => self::USER_ID,
                    'sessionToken' => self::SESSION_TOKEN,
                    'keepSessionId' => Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id,
                ]]
                : [],
            $ends,
        );
        self::assertSame(
            $signOutOthers ? [] : [[
                'userId' => self::USER_ID,
                'keepSessionId' => Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id,
            ]],
            $revokes,
        );
    }
}

/** Fixture code channel representing an installation that can text codes. */
final class PasswordChangeCodeChannel extends CodeChannel
{
    /** @return string Fixture channel name */
    public function name(): string
    {
        return 'password-change-sms';
    }

    /**
     * @param string $type Verification type
     * @return bool Whether this code travels by SMS
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }
}

/** Phone-capable fixture registry. */
final class PasswordChangeCodeChannelRegistry extends CodeChannelRegistry
{
    /** @return array<string, CodeChannel> Available fixture channels */
    protected static function channels(): array
    {
        $channel = new PasswordChangeCodeChannel();

        return [$channel->name() => $channel];
    }
}

/** Fixture facade declaring phone-code availability. */
final class PasswordChangeIntegrationHilos extends Hilos
{
    protected const string CODE_CHANNEL_REGISTRY = PasswordChangeCodeChannelRegistry::class;

    /** @return HilosDbContext Fixture context; the integration base supplies the live instance */
    protected static function createDb(): HilosDbContext
    {
        return new HilosSessionTestDbContext();
    }
}

/** The operation disabled by administration still has its own address proof. */
final class PasswordChangeDisabledSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Fixture defaults */
    public static function getCatalog(): array
    {
        $catalog = ProfileIntegrationSettingsCatalog::getCatalog();
        $catalog[StepUpSettings::DISABLED_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]
            = StepUpOperationKey::CHANGE_PASSWORD;

        return $catalog;
    }
}
