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
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpeningReplyDTO;
use Hilos\Auth\Library\DTO\AuthOtherSessionsEndSignalData;
use Hilos\Auth\Library\DTO\ProfileChangePasswordOpenActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeRequestActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordCodeConfirmActionDTO;
use Hilos\Auth\Library\DTO\ProfileChangePasswordActionDTO;
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
    public function testAddSmsConfirmTrimsCode(): void
    {
        $dto = ProfileAddSmsConfirmActionDTO::fromArray(['code' => '  123456  ']);
        $this->assertSame('123456', $dto->code);
    }

    /**
     * An add-phone confirm payload without a string code is refused.
     */
    public function testAddSmsConfirmRefusesNonStringFields(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileAddSmsConfirmActionDTO::fromArray(['code' => 42]);
    }

    /**
     * The add-phone confirm DTO is valid only with a non-empty code.
     */
    public function testAddSmsConfirmIsValidRequiresCode(): void
    {
        $this->assertTrue(ProfileAddSmsConfirmActionDTO::fromArray(['code' => '123456'])->isValid());
        $this->assertFalse(ProfileAddSmsConfirmActionDTO::fromArray(['code' => ''])->isValid());
    }

    /**
     * The add-phone confirm DTO round-trips its fields through toArray.
     */
    public function testAddSmsConfirmToArrayShape(): void
    {
        $this->assertSame(
            ['code' => '123456'],
            new ProfileAddSmsConfirmActionDTO('123456')->toArray(),
        );
    }

    /** Whitespace remains part of the new password, and no current password is carried. */
    public function testSetPasswordKeepsTheNewPasswordUntrimmed(): void
    {
        $dto = ProfileSetPasswordActionDTO::fromArray(['newPassword' => ' new-secret ']);

        self::assertSame(' new-secret ', $dto->newPassword);
        self::assertSame(['newPassword' => ' new-secret '], $dto->toArray());
        self::assertTrue($dto->isValid());
        self::assertFalse(new ProfileSetPasswordActionDTO('')->isValid());
    }

    /** The add action still needs its new password field. */
    public function testSetPasswordRefusesAPayloadWithoutTheNewPassword(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileSetPasswordActionDTO::fromArray([]);
    }

    /**
     * Only the code is trimmed; the session choice remains a boolean. The final submit carries no
     * code: the proof is the session's record (HIL-1182), and a code sent anyway is not read.
     */
    public function testPasswordChangePayloadsRoundTrip(): void
    {
        self::assertSame([], ProfileChangePasswordOpenActionDTO::fromArray(['userId' => 99])->toArray());
        self::assertSame([], ProfileChangePasswordCodeRequestActionDTO::fromArray(['email' => 'ignored'])->toArray());
        self::assertSame(['code' => '123456'], ProfileChangePasswordCodeConfirmActionDTO::fromArray(['code' => ' 123456 '])->toArray());
        $dto = ProfileChangePasswordActionDTO::fromArray(['code' => '123456', 'newPassword' => ' secret ', 'signOutOthers' => false]);
        self::assertSame(['newPassword' => ' secret ', 'signOutOthers' => false], $dto->toArray());
        self::assertTrue($dto->isValid());
        self::assertFalse(new ProfileChangePasswordActionDTO('', true)->isValid());
    }

    /** A truthy string must not turn into consent to sign out other sessions. */
    public function testPasswordChangeRefusesANonBooleanSessionChoice(): void
    {
        $this->expectException(InvalidFormatException::class);
        ProfileChangePasswordActionDTO::fromArray(['newPassword' => 'secret', 'signOutOthers' => 'false']);
    }

    /** Each action field is required. */
    public function testPasswordChangeRefusesMissingMembers(): void
    {
        $payload = ['newPassword' => 'secret', 'signOutOthers' => true];
        foreach (array_keys($payload) as $key) {
            $incomplete = $payload;
            unset($incomplete[$key]);
            try {
                ProfileChangePasswordActionDTO::fromArray($incomplete);
                self::fail("Missing {$key} must be refused");
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
    }

    /** Both reachable destinations and no-address answers survive the reply boundary. */
    public function testPasswordChangeOpeningReplyRoundTrips(): void
    {
        foreach ([['email', 'a@example.test'], ['phone', '+15551234567'], [null, null]] as [$channel, $destination]) {
            $dto = new ProfileChangePasswordOpeningReplyDTO($channel, $destination);
            self::assertSame($dto->toArray(), ProfileChangePasswordOpeningReplyDTO::fromArray($dto->toArray())->toArray());
        }
    }

    /** The session holder receives the person and token to preserve without losing either. */
    public function testOtherSessionsEndFrameRoundTrips(): void
    {
        $dto = new AuthOtherSessionsEndSignalData(300, 'acting-session');
        self::assertSame(['userId' => 300, 'sessionToken' => 'acting-session'], $dto->toArray());
        self::assertSame($dto->toArray(), AuthOtherSessionsEndSignalData::fromJson($dto->toJson())->toArray());
        $this->expectException(InvalidFormatException::class);
        AuthOtherSessionsEndSignalData::fromArray(['userId' => 300]);
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
     * The add-password request trims the address; confirmation trims only the code.
     */
    public function testAddPasswordStepsTrimAddressAndCodeButNotThePassword(): void
    {
        $request = ProfileAddPasswordRequestActionDTO::fromArray(['email' => '  a@example.com  ']);
        $confirm = ProfileAddPasswordConfirmActionDTO::fromArray([
            'code' => '  123456  ',
            'newPassword' => ' secret ',
        ]);

        $this->assertSame(['email' => 'a@example.com'], $request->toArray());
        $this->assertTrue($request->isValid());
        $this->assertSame(['code' => '123456', 'newPassword' => ' secret '], $confirm->toArray());
        $this->assertTrue($confirm->isValid());
        $this->assertFalse(new ProfileAddPasswordConfirmActionDTO('123456', '')->isValid());
    }

    /**
     * The email-change steps carry exactly their fields, trimmed; the first carries none. Neither
     * later step carries the current address's code any more, and the last does not repeat the new
     * address: both live in the session's record (HIL-1182), and a field sent anyway is not read.
     */
    public function testEmailChangeStepsCarryTheirFieldsTrimmed(): void
    {
        $this->assertSame([], ProfileEmailChangeCurrentRequestActionDTO::fromArray(['email' => 'ignored@example.com'])->toArray());
        $this->assertSame(['code' => '111111'], ProfileEmailChangeCurrentConfirmActionDTO::fromArray(['code' => ' 111111 '])->toArray());
        $this->assertSame(
            ['email' => 'new@example.com'],
            ProfileEmailChangeNewRequestActionDTO::fromArray(['currentCode' => '111111', 'email' => ' new@example.com '])->toArray(),
        );
        $this->assertSame(
            ['code' => '222222'],
            ProfileEmailChangeNewConfirmActionDTO::fromArray([
                'currentCode' => '111111',
                'email' => 'new@example.com',
                'code' => ' 222222 ',
            ])->toArray(),
        );
    }

    /**
     * The new-address step is refused without the address, and the last step without its code.
     */
    public function testEmailChangeLaterStepsRefuseAPayloadWithoutTheirField(): void
    {
        try {
            ProfileEmailChangeNewRequestActionDTO::fromArray([]);
            self::fail('A new-address request without the address must be refused');
        } catch (InvalidFormatException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidFormatException::class);

        ProfileEmailChangeNewConfirmActionDTO::fromArray([]);
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
        $this->assertSame(HilosSignalConstants::PROFILE_SET_PASSWORD, new ProfileSetPasswordActionDTO('x')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_UNLINK_IDENTITY, new ProfileUnlinkIdentityActionDTO(1)->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_SMS_REQUEST, new ProfileAddSmsRequestActionDTO('p')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM, new ProfileAddSmsConfirmActionDTO('c')->getAction());
        $this->assertSame(HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST, new ProfileAddPasswordRequestActionDTO('e')->getAction());
        $this->assertSame(
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            new ProfileAddPasswordConfirmActionDTO('c', 'p')->getAction(),
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
            new ProfileEmailChangeNewRequestActionDTO('e')->getAction(),
        );
        $this->assertSame(
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
            new ProfileEmailChangeNewConfirmActionDTO('c')->getAction(),
        );
        self::assertSame(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN, new ProfileChangePasswordOpenActionDTO()->getAction());
        self::assertSame(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST, new ProfileChangePasswordCodeRequestActionDTO()->getAction());
        self::assertSame(HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM, new ProfileChangePasswordCodeConfirmActionDTO('c')->getAction());
        self::assertSame(HilosSignalConstants::PROFILE_CHANGE_PASSWORD, new ProfileChangePasswordActionDTO('p', true)->getAction());
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
