<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\DTO\LinkOAuthStartActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddPasswordRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileAddSmsRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeCurrentRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileEmailChangeNewRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfilePasswordUpdatedSignalData;
use Hilos\Auth\Library\DTO\ProfileSetPasswordActionDTO;
use Hilos\Auth\Library\DTO\ProfileUnlinkIdentityActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the profile's sign-in-method and email-change payloads (HIL-1137).
 *
 * Locks the parse-trim shape the commands rely on, the wire name each DTO answers to, and
 * the one server → client frame. The owning user is never carried in these payloads: every
 * command reads the person from the acting session.
 */
final class ProfileActionDTOTest extends TestCase
{
    /**
     * The add-phone request DTO trims the phone it was sent.
     */
    public function testAddSmsRequestTrimsPhone(): void
    {
        $this->assertSame('+15551234', ProfileAddSmsRequestActionDTO::fromArray(['phone' => '  +15551234  '])->phone);
    }

    /**
     * A payload with no phone at all is refused, not read as a blank one.
     */
    public function testAddSmsRequestRefusesAPayloadWithoutAPhone(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileAddSmsRequestActionDTO::fromArray([]);
    }

    /**
     * A phone that is not a string is refused the same way an absent one is.
     */
    public function testAddSmsRequestRefusesANonStringPhone(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileAddSmsRequestActionDTO::fromArray(['phone' => 123]);
    }

    /**
     * The add-phone request DTO is valid only with a non-empty phone.
     */
    public function testAddSmsRequestIsValidRequiresPhone(): void
    {
        $this->assertTrue(ProfileAddSmsRequestActionDTO::fromArray(['phone' => '+15551234'])->isValid());
        $this->assertFalse(ProfileAddSmsRequestActionDTO::fromArray(['phone' => '   '])->isValid());
    }

    /**
     * The add-phone request DTO round-trips its phone through toArray.
     */
    public function testAddSmsRequestToArrayShape(): void
    {
        $this->assertSame(['phone' => '+15551234'], new ProfileAddSmsRequestActionDTO('+15551234')->toArray());
    }

    /**
     * The add-phone confirm DTO trims both fields.
     */
    public function testAddSmsConfirmTrimsPhoneAndCode(): void
    {
        $dto = ProfileAddSmsConfirmActionDTO::fromArray(['phone' => '  +15551234  ', 'code' => '  123456  ']);
        $this->assertSame('+15551234', $dto->phone);
        $this->assertSame('123456', $dto->code);
    }

    /**
     * An add-phone confirm payload whose fields are not strings is refused.
     */
    public function testAddSmsConfirmRefusesNonStringFields(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileAddSmsConfirmActionDTO::fromArray(['phone' => null, 'code' => 42]);
    }

    /**
     * The add-phone confirm DTO is valid only with a non-empty phone and code.
     */
    public function testAddSmsConfirmIsValidRequiresPhoneAndCode(): void
    {
        $this->assertTrue(ProfileAddSmsConfirmActionDTO::fromArray(['phone' => '+15551234', 'code' => '123456'])->isValid());
        $this->assertFalse(ProfileAddSmsConfirmActionDTO::fromArray(['phone' => '+15551234', 'code' => ''])->isValid());
        $this->assertFalse(ProfileAddSmsConfirmActionDTO::fromArray(['phone' => '', 'code' => '123456'])->isValid());
    }

    /**
     * The add-phone confirm DTO round-trips its fields through toArray.
     */
    public function testAddSmsConfirmToArrayShape(): void
    {
        $this->assertSame(
            ['phone' => '+15551234', 'code' => '123456'],
            new ProfileAddSmsConfirmActionDTO('+15551234', '123456')->toArray(),
        );
    }

    /**
     * The password submit keeps both passwords as sent - whitespace in a password is part of it.
     */
    public function testSetPasswordKeepsBothPasswordsUntrimmed(): void
    {
        $dto = ProfileSetPasswordActionDTO::fromArray(['currentPassword' => ' old ', 'newPassword' => ' new-secret ']);

        $this->assertSame(' old ', $dto->currentPassword);
        $this->assertSame(' new-secret ', $dto->newPassword);
        $this->assertSame(['currentPassword' => ' old ', 'newPassword' => ' new-secret '], $dto->toArray());
    }

    /**
     * The password submit is valid only with a new password; the current one may be empty (the add branch).
     */
    public function testSetPasswordIsValidRequiresOnlyTheNewPassword(): void
    {
        $this->assertTrue(new ProfileSetPasswordActionDTO('', 'new-secret')->isValid());
        $this->assertFalse(new ProfileSetPasswordActionDTO('old', '')->isValid());
    }

    /**
     * A password submit without the current-password field is refused rather than read as an add.
     */
    public function testSetPasswordRefusesAPayloadWithoutTheCurrentPasswordField(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileSetPasswordActionDTO::fromArray(['newPassword' => 'new-secret']);
    }

    /**
     * The unlink reads an integer id and is valid only for a positive one.
     */
    public function testUnlinkReadsTheIdentityId(): void
    {
        $dto = ProfileUnlinkIdentityActionDTO::fromArray(['identityId' => 42]);

        $this->assertSame(42, $dto->identityId);
        $this->assertTrue($dto->isValid());
        $this->assertFalse(new ProfileUnlinkIdentityActionDTO(0)->isValid());
        $this->assertSame(['identityId' => 42], $dto->toArray());
    }

    /**
     * An unlink that names no identity is refused at the parse.
     */
    public function testUnlinkRefusesAPayloadWithoutAnId(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileUnlinkIdentityActionDTO::fromArray([]);
    }

    /**
     * The add-password steps trim the address and the code, never the password.
     */
    public function testAddPasswordStepsTrimAddressAndCodeButNotThePassword(): void
    {
        $request = ProfileAddPasswordRequestActionDTO::fromArray(['email' => '  a@example.com  ']);
        $confirm = ProfileAddPasswordConfirmActionDTO::fromArray([
            'email' => '  a@example.com  ',
            'code' => '  123456  ',
            'newPassword' => ' secret ',
        ]);

        $this->assertSame(['email' => 'a@example.com'], $request->toArray());
        $this->assertTrue($request->isValid());
        $this->assertSame(['email' => 'a@example.com', 'code' => '123456', 'newPassword' => ' secret '], $confirm->toArray());
        $this->assertTrue($confirm->isValid());
        $this->assertFalse(new ProfileAddPasswordConfirmActionDTO('a@example.com', '123456', '')->isValid());
    }

    /**
     * The email-change steps carry exactly their fields, trimmed; the first carries none.
     */
    public function testEmailChangeStepsCarryTheirFieldsTrimmed(): void
    {
        $this->assertSame([], ProfileEmailChangeCurrentRequestActionDTO::fromArray(['email' => 'ignored@example.com'])->toArray());
        $this->assertSame(['code' => '111111'], ProfileEmailChangeCurrentConfirmActionDTO::fromArray(['code' => ' 111111 '])->toArray());
        $this->assertSame(
            ['currentCode' => '111111', 'email' => 'new@example.com'],
            ProfileEmailChangeNewRequestActionDTO::fromArray(['currentCode' => ' 111111 ', 'email' => ' new@example.com '])->toArray(),
        );
        $this->assertSame(
            ['currentCode' => '111111', 'email' => 'new@example.com', 'code' => '222222'],
            ProfileEmailChangeNewConfirmActionDTO::fromArray([
                'currentCode' => ' 111111 ',
                'email' => ' new@example.com ',
                'code' => ' 222222 ',
            ])->toArray(),
        );
    }

    /**
     * A step that carries the current address's code is refused without it.
     */
    public function testEmailChangeNewRequestRefusesAPayloadWithoutTheCurrentCode(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileEmailChangeNewRequestActionDTO::fromArray(['email' => 'new@example.com']);
    }

    /**
     * The link start reads the provider and the trip id as sent.
     */
    public function testLinkOAuthStartRoundTrips(): void
    {
        $dto = LinkOAuthStartActionDTO::fromArray(['provider' => 'oauth:github', 'tripId' => 'trip-1']);

        $this->assertSame(['provider' => 'oauth:github', 'tripId' => 'trip-1'], $dto->toArray());
    }

    /**
     * Every profile DTO answers to its own wire name - the name is the address the router hands it to.
     */
    public function testEachDtoAnswersToItsWireName(): void
    {
        $this->assertSame(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO('', 'x')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_UNLINK_IDENTITY, new ProfileUnlinkIdentityActionDTO(1)->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_SMS_REQUEST, new ProfileAddSmsRequestActionDTO('p')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM, new ProfileAddSmsConfirmActionDTO('p', 'c')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST, new ProfileAddPasswordRequestActionDTO('e')->getAction());
        $this->assertSame(
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO('e', 'c', 'p')->getAction(),
        );
        $this->assertSame(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
            new ProfileEmailChangeCurrentRequestActionDTO()->getAction(),
        );
        $this->assertSame(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
            new ProfileEmailChangeCurrentConfirmActionDTO('c')->getAction(),
        );
        $this->assertSame(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
            new ProfileEmailChangeNewRequestActionDTO('c', 'e')->getAction(),
        );
        $this->assertSame(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO('c', 'e', 'c')->getAction(),
        );
        $this->assertSame(HilosSignalConstants::HILOS_LINK_OAUTH_START, new LinkOAuthStartActionDTO('p', 't')->getAction());
    }

    /**
     * The password-updated frame carries its mode and refuses a payload that names none.
     */
    public function testPasswordUpdatedSignalRoundTripsItsMode(): void
    {
        $data = new ProfilePasswordUpdatedSignalData(ProfilePasswordUpdatedSignalData::MODE_ADDED);

        $this->assertSame(['mode' => 'added'], $data->toArray());
        $this->assertSame('added', ProfilePasswordUpdatedSignalData::fromArray($data->toArray())->mode);

        $this->expectException(InvalidFormatException::class);
        ProfilePasswordUpdatedSignalData::fromArray([]);
    }
}
