<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Template\EmailChangedMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * The profile email change, run by the framework's users library (HIL-299, HIL-495, HIL-1137).
 *
 * Four submits, one proof carried between them, and the account moved only on the last one.
 * Step 1 mails a code to the address the account holds; step 2 checks it without spending
 * it; step 3 judges the new address and mails it a code, carrying the first code as proof;
 * step 4 spends the new address's code, then the proof, moves every password and sign-in-link
 * row of the old address inside one transaction, and notifies both addresses. Every step
 * opens with the operation's confirmation. What is pinned here is the ORDER: which refusal
 * spends what, so that a typo costs nothing and a lost race changes nothing.
 */
final class ProfileEmailChangeIntegrationTest extends ProfileIntegrationTestCase
{
    private const string CURRENT = 'current@example.test';
    private const string NEW_EMAIL = 'new@example.test';
    private const string TAKEN = 'taken@example.test';
    private const string CURRENT_CODE = '424242';
    private const string NEW_CODE = '535353';
    private const string WRONG_CODE = '000000';
    private const string PASSWORD = 'a-long-enough-secret';

    /**
     * An account whose only proof is its address passes the confirmation and is mailed a code.
     *
     * No double code (HIL-495): the step mails the same mailbox a confirmation would.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testStepOneMailsACodeToTheConfirmedAddress(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);

        $this->submit(HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST, new ProfileEmailChangeCurrentRequestActionDTO());

        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame(
            [[self::CURRENT, MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE_CURRENT),
        );
    }

    /**
     * An account with no confirmed address has no current mailbox to prove.
     *
     * @throws HilosException When the confirmation cannot be written
     */
    public function testStepOneRefusesAnAccountWithoutAConfirmedAddress(): void
    {
        $this->confirmStepUp();

        $this->assertRefused(
            AuthMessages::CONFIRM_EMAIL_FIRST,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
            new ProfileEmailChangeCurrentRequestActionDTO(),
        );

        self::assertSame([], $this->mailer->sent);
    }

    /**
     * A password account must freshly confirm the operation before step 1.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepOneRefusesAPasswordAccountWithoutAConfirmation(): void
    {
        $this->seedPasswordAccount();

        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
            new ProfileEmailChangeCurrentRequestActionDTO(),
        );

        self::assertSame([], $this->mailer->sent);
    }

    /**
     * Nobody changes the address of an account they only work in on somebody else's behalf.
     *
     * @throws HilosException When the seed or the session update fails
     */
    public function testEveryStepRefusesAnImpersonatedSession(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::OTHER_USER_ID, self::SESSION_TOKEN],
        );

        $this->assertRefused(
            StepUpMessages::IMPERSONATED,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
            new ProfileEmailChangeCurrentRequestActionDTO(),
        );
        $this->assertRefused(
            StepUpMessages::IMPERSONATED,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO(self::CURRENT_CODE, self::NEW_EMAIL, self::NEW_CODE),
        );
    }

    /**
     * The right code passes step 2 and stays alive, because steps 3 and 4 carry it.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testStepTwoAcceptsTheRightCodeWithoutSpendingIt(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::USER_ID, self::CURRENT_CODE);

        $this->submit(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
            new ProfileEmailChangeCurrentConfirmActionDTO(self::CURRENT_CODE),
        );

        self::assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS),
            'A proven code must survive step 2',
        );
    }

    /**
     * A wrong code on step 2 is answered with the one generic sentence.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepTwoRefusesAWrongCode(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::USER_ID, self::CURRENT_CODE);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
            new ProfileEmailChangeCurrentConfirmActionDTO(self::WRONG_CODE),
        );
    }

    /**
     * A malformed address, the account's own, and another account's are refused with no code mailed.
     *
     * None of the three spends the proof: none of them could have been changed by a code.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepThreeRefusesAnAddressItCannotMoveToWithoutMailingIt(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::MAGIC_LINK, self::TAKEN);
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::USER_ID, self::CURRENT_CODE);

        $cases = [
            'not-an-address' => AuthMessages::INVALID_EMAIL,
            strtoupper(self::CURRENT) => AuthMessages::ALREADY_YOUR_ADDRESS,
            self::TAKEN => AuthMessages::EMAIL_IN_USE,
        ];
        foreach ($cases as $address => $message) {
            $this->assertRefused(
                $message,
                HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
                new ProfileEmailChangeNewRequestActionDTO(self::CURRENT_CODE, $address),
            );
            self::assertNull(
                $this->verifications()->findActive(VerificationType::EMAIL_CHANGE, strtolower($address), self::MAX_ATTEMPTS),
                "{$address} must not be mailed a code",
            );
        }

        self::assertSame([], $this->mailer->sent);
        self::assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS),
            'An address refusal must not spend the proof',
        );
    }

    /**
     * With the proof of the current address gone, step 3 asks the person to start over.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepThreeWithoutALiveProofAsksToStartAgain(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);

        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
            new ProfileEmailChangeNewRequestActionDTO(self::CURRENT_CODE, self::NEW_EMAIL),
        );

        self::assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, self::NEW_EMAIL, self::MAX_ATTEMPTS));
    }

    /**
     * A free address with a live proof gets an `email_change` code carrying the person.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testStepThreeMailsACodeToTheNewAddress(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::USER_ID, self::CURRENT_CODE);

        $this->submit(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
            new ProfileEmailChangeNewRequestActionDTO(self::CURRENT_CODE, strtoupper(self::NEW_EMAIL)),
        );

        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE, self::NEW_EMAIL, self::MAX_ATTEMPTS)?->userId,
        );
        self::assertSame(
            [[self::NEW_EMAIL, MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE),
        );
    }

    /**
     * Step 4 spends both codes, moves the password and link rows, and notifies both addresses.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testStepFourMovesTheAccountAndNotifiesBothAddresses(): void
    {
        $this->seedPasswordAccount();
        $this->confirmStepUp();
        $this->seedBothCodes();

        $this->submit(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO(self::CURRENT_CODE, self::NEW_EMAIL, self::NEW_CODE),
        );

        self::assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS));
        self::assertNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, self::NEW_EMAIL, self::MAX_ATTEMPTS));
        self::assertSame(
            [
                [IdentityType::MAGIC_LINK, self::NEW_EMAIL, true],
                [IdentityType::PASSWORD, self::NEW_EMAIL, true],
            ],
            self::rowsOf(self::USER_ID),
        );
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        $params = [EmailChangedMailTemplate::PARAM_WAS => self::CURRENT, EmailChangedMailTemplate::PARAM_NOW => self::NEW_EMAIL];
        self::assertSame(
            [
                ['to' => self::CURRENT, 'templateKey' => MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED, 'params' => $params],
                ['to' => self::NEW_EMAIL, 'templateKey' => MailTemplateCatalogConstants::ACCOUNT_EMAIL_CHANGED, 'params' => $params],
            ],
            $this->mailer->sent,
        );
    }

    /**
     * A wrong code from the new address leaves the proof alive, so the typo can be corrected.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepFourWithAWrongNewCodeKeepsTheProofAndTheAddress(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedBothCodes();

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO(self::CURRENT_CODE, self::NEW_EMAIL, self::WRONG_CODE),
        );

        self::assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS),
            'A typo in the new code must not spend the proof',
        );
        self::assertSame([[IdentityType::MAGIC_LINK, self::CURRENT, true]], self::rowsOf(self::USER_ID));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * A proof another tab already spent asks the person to start over, and nothing moves.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepFourWithAProofSpentElsewhereAsksToStartAgain(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedBothCodes();
        $this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS)?->consume();

        $this->assertRefused(
            StepUpMessages::EXPIRED,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO(self::CURRENT_CODE, self::NEW_EMAIL, self::NEW_CODE),
        );

        self::assertSame([[IdentityType::MAGIC_LINK, self::CURRENT, true]], self::rowsOf(self::USER_ID));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * An address another account took between the steps is refused before either code is spent.
     *
     * @throws HilosException When the seed fails
     */
    public function testStepFourWithTheAddressTakenMeanwhileSpendsNoCode(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        $this->seedBothCodes();
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::MAGIC_LINK, self::NEW_EMAIL);

        $this->assertRefused(
            AuthMessages::EMAIL_IN_USE,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO(self::CURRENT_CODE, self::NEW_EMAIL, self::NEW_CODE),
        );

        self::assertNotNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::MAX_ATTEMPTS));
        self::assertNotNull($this->verifications()->findActive(VerificationType::EMAIL_CHANGE, self::NEW_EMAIL, self::MAX_ATTEMPTS));
        self::assertSame([[IdentityType::MAGIC_LINK, self::CURRENT, true]], self::rowsOf(self::USER_ID));
    }

    /**
     * Seeds an account whose confirmed address also carries its password.
     *
     * @throws HilosException When an identity cannot be written
     */
    private function seedPasswordAccount(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::CURRENT);
        Hilos::$db->identities->createPasswordIdentity(self::USER_ID, self::CURRENT, self::PASSWORD)->markVerified();
    }

    /**
     * Seeds the live proof of the current address and the code of the new one.
     *
     * @throws HilosException When a challenge insert fails
     */
    private function seedBothCodes(): void
    {
        $this->seedCode(VerificationType::EMAIL_CHANGE_CURRENT, self::CURRENT, self::USER_ID, self::CURRENT_CODE);
        $this->seedCode(VerificationType::EMAIL_CHANGE, self::NEW_EMAIL, self::USER_ID, self::NEW_CODE);
    }

    /**
     * Seeds the operation confirmation the real confirmation command would write for the tab.
     *
     * @throws HilosException When the confirmation cannot be written
     */
    private function confirmStepUp(): void
    {
        Hilos::$db->stepUps->actions->confirm(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID,
            StepUpOperationKey::CHANGE_EMAIL,
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        );
    }
}
