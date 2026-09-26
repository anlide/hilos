<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileUnlinkIdentityActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\Template\MailTemplateCatalogConstants;

/**
 * The profile's own ways in, run by the framework's users library (HIL-402, HIL-403, HIL-406, HIL-1137).
 *
 * A password is changed with the current one or added to a confirmed address; a phone and a
 * password by mail are added in two steps with a code; a way in is taken off by id. What is
 * pinned is the order of each command's refusals - what is asked before a code is spent, and
 * that the password gate speaks only after the current password did (HIL-654) - and that the
 * password-updated frame reaches every tab of the person, not only the one that saved.
 */
final class ProfileSignInMethodsIntegrationTest extends ProfileIntegrationTestCase
{
    private const string EMAIL = 'profile@example.test';
    private const string OTHER_EMAIL = 'someone-else@example.test';
    private const string PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'a-brand-new-secret';
    private const string PHONE = '+15551231137';
    private const string CODE = '424242';
    private const string WRONG_CODE = '000000';

    /**
     * A change re-hashes the secret and tells every tab of the person it was changed.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testChangePasswordRewritesTheSecretAndSignalsEveryTab(): void
    {
        $this->seedPassword();

        $this->submit(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO(self::PASSWORD, self::NEW_PASSWORD));

        $identity = Hilos::$db->identities->findPasswordByUser(self::USER_ID);
        self::assertTrue($identity?->verifyPassword(self::NEW_PASSWORD));
        self::assertFalse($identity->verifyPassword(self::PASSWORD));
        self::assertSame(
            [
                [self::ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_CHANGED],
                [self::OTHER_ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_CHANGED],
            ],
            $this->passwordUpdates(),
        );
    }

    /**
     * A change to the password already in force is refused, and nothing is announced (HIL-654).
     *
     * @throws HilosException When the seed fails
     */
    public function testChangePasswordToTheCurrentOneIsRefused(): void
    {
        $this->seedPassword();

        try {
            $this->submit(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO(self::PASSWORD, self::PASSWORD));
            self::fail('The password already in force must be refused');
        } catch (PasswordUnchangedException) {
            // refused
        }

        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        self::assertSame([], $this->passwordUpdates(), 'Nothing changed, so the tabs are told nothing');
    }

    /**
     * A wrong current password is refused first - even when the new one would fail the policy too.
     *
     * @throws HilosException When the seed fails
     */
    public function testChangePasswordWithAWrongCurrentOneIsRefusedBeforeThePolicy(): void
    {
        $this->seedPassword();

        $this->assertRefused(
            AuthMessages::CURRENT_PASSWORD_INCORRECT,
            HilosSignalConstants::PROFILE_SET_PASSWORD,
            new ProfileSetPasswordActionDTO('not the password', self::NEW_PASSWORD),
        );
        $this->assertRefused(
            AuthMessages::CURRENT_PASSWORD_INCORRECT,
            HilosSignalConstants::PROFILE_SET_PASSWORD,
            new ProfileSetPasswordActionDTO('', 'short'),
        );

        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword(self::PASSWORD));
        self::assertSame([], $this->passwordUpdates());
    }

    /**
     * An account with a confirmed address and no password gets a confirmed one without a code.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAddPasswordOnAConfirmedAddressCreatesAConfirmedIdentity(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);

        $this->submit(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO('', self::NEW_PASSWORD));

        $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, self::EMAIL);
        self::assertSame(self::USER_ID, $identity?->userId);
        self::assertTrue($identity->verified);
        self::assertTrue($identity->verifyPassword(self::NEW_PASSWORD));
        self::assertSame(
            [
                [self::ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_ADDED],
                [self::OTHER_ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_ADDED],
            ],
            $this->passwordUpdates(),
        );
    }

    /**
     * An account without a confirmed address is sent to prove one first.
     *
     * @throws HilosException When the command fails for another reason
     */
    public function testAddPasswordWithoutAConfirmedAddressIsRefused(): void
    {
        $this->assertRefused(
            AuthMessages::CONFIRM_EMAIL_FIRST,
            HilosSignalConstants::PROFILE_SET_PASSWORD,
            new ProfileSetPasswordActionDTO('', self::NEW_PASSWORD),
        );

        self::assertSame([], self::rowsOf(self::USER_ID));
        self::assertSame([], $this->passwordUpdates());
    }

    /**
     * A tab whose session lost its person is refused before anything is read.
     *
     * @throws HilosException When the command fails for another reason
     */
    public function testASignedOutTabIsRefused(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);
        $this->expectExceptionMessage('User session not found');

        $this->submit(
            HilosSignalConstants::PROFILE_SET_PASSWORD,
            new ProfileSetPasswordActionDTO('', self::NEW_PASSWORD),
            self::ANONYMOUS_ACCEPT_KEY,
        );
    }

    /**
     * A free address is issued an `email_add` code for the person, and mailed.
     *
     * @throws HilosException When the command fails
     */
    public function testAddPasswordRequestMailsACodeToAFreeAddress(): void
    {
        $this->submit(HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST, new ProfileAddPasswordRequestActionDTO(strtoupper(self::EMAIL)));

        $challenge = $this->verifications()->findActive(VerificationType::EMAIL_ADD, self::EMAIL, self::MAX_ATTEMPTS);
        self::assertSame(self::USER_ID, $challenge?->userId);
        self::assertSame(
            [[self::EMAIL, MailTemplateCatalogConstants::AUTH_EMAIL_ADD]],
            $this->mailer->sentTo(MailTemplateCatalogConstants::AUTH_EMAIL_ADD),
        );
    }

    /**
     * A malformed address, and one another account confirmed, are refused and mailed nothing.
     *
     * @throws HilosException When the seed fails
     */
    public function testAddPasswordRequestRefusesAnAddressItMayNotMail(): void
    {
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::MAGIC_LINK, self::OTHER_EMAIL);

        $this->assertRefused(
            AuthMessages::INVALID_EMAIL,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST,
            new ProfileAddPasswordRequestActionDTO('not-an-address'),
        );
        $this->assertRefused(
            AuthMessages::EMAIL_IN_USE,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST,
            new ProfileAddPasswordRequestActionDTO(self::OTHER_EMAIL),
        );

        self::assertNull($this->verifications()->findActive(VerificationType::EMAIL_ADD, self::OTHER_EMAIL, self::MAX_ATTEMPTS));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * The right code writes a confirmed password on the proven address and tells every tab.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAddPasswordConfirmCreatesAConfirmedPasswordAndSignalsAdded(): void
    {
        $this->seedCode(VerificationType::EMAIL_ADD, self::EMAIL, self::USER_ID, self::CODE);

        $this->submit(
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO(self::EMAIL, self::CODE, self::NEW_PASSWORD),
        );

        $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, self::EMAIL);
        self::assertSame(self::USER_ID, $identity?->userId);
        self::assertTrue($identity->verified);
        self::assertTrue($identity->verifyPassword(self::NEW_PASSWORD));
        self::assertSame(
            [
                [self::ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_ADDED],
                [self::OTHER_ACCEPT_KEY, ProfilePasswordUpdatedSignalData::MODE_ADDED],
            ],
            $this->passwordUpdates(),
        );
    }

    /**
     * An account that already has a password is refused a second one, and the code survives (HIL-692).
     *
     * @throws HilosException When the seed fails
     */
    public function testAddPasswordConfirmRefusesASecondPasswordWithoutSpendingTheCode(): void
    {
        $this->seedPassword();
        $this->seedCode(VerificationType::EMAIL_ADD, self::OTHER_EMAIL, self::USER_ID, self::CODE);

        $this->assertRefused(
            AuthMessages::ALREADY_HAS_PASSWORD,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO(self::OTHER_EMAIL, self::CODE, self::NEW_PASSWORD),
        );

        self::assertSame(self::EMAIL, Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->identifier);
        self::assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_ADD, self::OTHER_EMAIL, self::MAX_ATTEMPTS),
            'A refusal the code could not have changed must not burn it',
        );
        self::assertSame([], $this->passwordUpdates());
    }

    /**
     * A weak password is refused before the code is looked at, so the code survives.
     *
     * @throws HilosException When the seed fails
     */
    public function testAddPasswordConfirmRefusesAWeakPasswordBeforeTheCode(): void
    {
        $this->seedCode(VerificationType::EMAIL_ADD, self::EMAIL, self::USER_ID, self::CODE);

        try {
            $this->submit(
                HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
                new ProfileAddPasswordConfirmActionDTO(self::EMAIL, self::CODE, 'short'),
            );
            self::fail('A weak password must be refused');
        } catch (ValueTooShortException) {
            // refused
        }

        self::assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, self::EMAIL));
        self::assertNotNull(
            $this->verifications()->findActive(VerificationType::EMAIL_ADD, self::EMAIL, self::MAX_ATTEMPTS),
            'A weak password must not burn the code',
        );
    }

    /**
     * A wrong code, and one minted for somebody else, are answered alike and write nothing.
     *
     * @throws HilosException When the seed fails
     */
    public function testAddPasswordConfirmRefusesAWrongOrForeignCode(): void
    {
        $this->seedCode(VerificationType::EMAIL_ADD, self::EMAIL, self::USER_ID, self::CODE);
        $this->seedCode(VerificationType::EMAIL_ADD, self::OTHER_EMAIL, self::OTHER_USER_ID, self::CODE);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO(self::EMAIL, self::WRONG_CODE, self::NEW_PASSWORD),
        );
        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO(self::OTHER_EMAIL, self::CODE, self::NEW_PASSWORD),
        );

        self::assertSame([], self::rowsOf(self::USER_ID));
        self::assertSame([], $this->passwordUpdates());
    }

    /**
     * Step 1 of a phone issues an `sms_add` code for the person on the normalized number.
     *
     * @throws HilosException When the command fails
     */
    public function testAddSmsRequestIssuesACodeForTheNormalizedNumber(): void
    {
        $this->submit(HilosSignalConstants::PROFILE_ADD_SMS_REQUEST, new ProfileAddSmsRequestActionDTO('+1 555 123 1137'));

        self::assertSame(
            self::USER_ID,
            $this->verifications()->findActive(VerificationType::SMS_ADD, self::PHONE, self::MAX_ATTEMPTS)?->userId,
        );
    }

    /**
     * A number that is not one is refused before any code is issued.
     *
     * @throws HilosException When the command fails for another reason
     */
    public function testAddSmsRequestRefusesAMalformedNumber(): void
    {
        $this->assertRefused(
            AuthMessages::INVALID_PHONE,
            HilosSignalConstants::PROFILE_ADD_SMS_REQUEST,
            new ProfileAddSmsRequestActionDTO('not a number'),
        );
    }

    /**
     * The right code attaches the number as a confirmed way in.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testAddSmsConfirmAttachesTheNumber(): void
    {
        $this->seedCode(VerificationType::SMS_ADD, self::PHONE, self::USER_ID, self::CODE);

        $this->submit(HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM, new ProfileAddSmsConfirmActionDTO(self::PHONE, self::CODE));

        self::assertSame([[IdentityType::SMS, self::PHONE, true]], self::rowsOf(self::USER_ID));
    }

    /**
     * A wrong code attaches nothing.
     *
     * @throws HilosException When the seed fails
     */
    public function testAddSmsConfirmRefusesAWrongCode(): void
    {
        $this->seedCode(VerificationType::SMS_ADD, self::PHONE, self::USER_ID, self::CODE);

        $this->assertRefused(
            AuthMessages::INVALID_CODE,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
            new ProfileAddSmsConfirmActionDTO(self::PHONE, self::WRONG_CODE),
        );

        self::assertSame([], self::rowsOf(self::USER_ID));
    }

    /**
     * A number another account holds is refused after the code proved it, and never moved.
     *
     * @throws HilosException When the seed fails
     */
    public function testAddSmsConfirmRefusesANumberAnotherAccountHolds(): void
    {
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::SMS, self::PHONE);
        $this->seedCode(VerificationType::SMS_ADD, self::PHONE, self::USER_ID, self::CODE);

        $this->assertRefused(
            AuthMessages::PHONE_IN_USE,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
            new ProfileAddSmsConfirmActionDTO(self::PHONE, self::CODE),
        );

        self::assertSame([], self::rowsOf(self::USER_ID));
        self::assertSame([[IdentityType::SMS, self::PHONE, true]], self::rowsOf(self::OTHER_USER_ID));
    }

    /**
     * An unlink that names no way in is refused before the command runs.
     *
     * @throws HilosException When the command fails for another reason
     */
    public function testUnlinkWithoutAnIdIsRefused(): void
    {
        $this->assertRefused(
            AuthMessages::IDENTITY_ID_REQUIRED,
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY,
            new ProfileUnlinkIdentityActionDTO(0),
        );
    }

    /**
     * An unlink takes the named way in off and leaves the other.
     *
     * @throws HilosException When the seed or the command fails
     */
    public function testUnlinkTakesTheNamedWayInOff(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        self::seedIdentity(self::USER_ID, IdentityType::SMS, self::PHONE);
        $phoneId = (int)Hilos::$db->identities->findByIdentity(IdentityType::SMS, self::PHONE)?->id;

        $this->submit(HilosSignalConstants::PROFILE_UNLINK_IDENTITY, new ProfileUnlinkIdentityActionDTO($phoneId));

        self::assertSame([[IdentityType::MAGIC_LINK, self::EMAIL, true]], self::rowsOf(self::USER_ID));
    }

    /**
     * @throws HilosException When the identity cannot be written
     */
    private function seedPassword(): void
    {
        Hilos::$db->identities->createPasswordIdentity(self::USER_ID, self::EMAIL, self::PASSWORD)->markVerified();
    }
}
