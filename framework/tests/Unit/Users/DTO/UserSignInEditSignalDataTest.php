<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserAddressVerifyDoneSignalData;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserEmailChangeDoneSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkDoneSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashDoneSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use PHPUnit\Framework\TestCase;

/** The frames that carry a person's ways of signing in to their agent survive the wire whole (HIL-1405). */
final class UserSignInEditSignalDataTest extends TestCase
{
    private const string HASH = '$2y$12$abcdefghijklmnopqrstuuJ5bXH3m0ZcJ0y1x2w3v4u5t6s7r8q9p';

    public function testARehashAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserPasswordRehashSignalData(
            userId: 7,
            identityId: 21,
            passwordHash: self::HASH,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'hilos_login',
            successMessage: null,
        );
        $done = UserPasswordRehashDoneSignalData::to($ask, null);

        $restored = UserPasswordRehashDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(self::HASH, $restored->ask->passwordHash);
        self::assertNull($restored->error);
    }

    public function testAnAddressVerifyAskAndItsRefusalRoundTrip(): void
    {
        $ask = new UserAddressVerifySignalData(
            7,
            21,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE,
            'key-1',
            null,
            'hilos_confirm_magic_link',
            null,
        );
        $done = UserAddressVerifyDoneSignalData::to($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserAddressVerifyDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
        self::assertNull($restored->ask->requestId);
    }

    public function testAPasskeyUseForASignInCarriesNoOperation(): void
    {
        $ask = new UserPasskeyUseSignalData(
            7,
            5,
            12,
            null,
            null,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            'key-1',
            'req-1',
            'hilos_passkey_login_confirm',
            null,
        );
        $done = UserPasskeyUseDoneSignalData::to($ask, null);

        $restored = UserPasskeyUseDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertNull($restored->ask->operation);
        self::assertSame(12, $restored->ask->signCount);
    }

    public function testAPasskeyUseForAStepCarriesItsOperation(): void
    {
        $ask = new UserPasskeyUseSignalData(
            7,
            5,
            13,
            'change_password',
            'session-hash',
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            'key-1',
            null,
            'hilos_step_up_confirm',
            null,
        );
        $done = UserPasskeyUseDoneSignalData::to($ask, ActionRefusal::said('Passkey sign-in could not be completed'));

        $restored = UserPasskeyUseDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertSame('change_password', $restored->ask->operation);
        self::assertSame('Passkey sign-in could not be completed', $restored->error);
        self::assertNull($restored->errorDetail);
    }

    public function testARecoveryAskCarriesTheGrantedAddress(): void
    {
        $ask = new UserPasswordResetSignalData(
            userId: 7,
            identityId: 21,
            passwordHash: self::HASH,
            email: 'ada@example.test',
            keepSessionId: 0,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'hilos_complete_password_reset',
            successMessage: null,
        );
        $done = UserPasswordResetDoneSignalData::to($ask, null);

        $restored = UserPasswordResetDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('ada@example.test', $restored->ask->email);
    }

    public function testAPasswordChangeAskCarriesWhatFollowsTheWrite(): void
    {
        $ask = new UserPasswordChangeSignalData(
            userId: 7,
            identityId: 21,
            passwordHash: self::HASH,
            signOutOthers: true,
            flowOpen: false,
            keepSessionId: 41,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'profile_change_password',
            successMessage: null,
        );
        $done = UserPasswordChangeDoneSignalData::to($ask, null);

        $restored = UserPasswordChangeDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertTrue($restored->ask->signOutOthers);
        self::assertFalse($restored->ask->flowOpen);
    }

    public function testAnEmailChangeAskAndItsRefusalRoundTrip(): void
    {
        $ask = new UserEmailChangeSignalData(
            7,
            'old@example.test',
            'new@example.test',
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE,
            'key-1',
            'req-1',
            'profile_change_email_new_confirm',
            null,
        );
        $done = UserEmailChangeDoneSignalData::to($ask, ActionRefusal::said('That email is already in use'));

        $restored = UserEmailChangeDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('old@example.test', $restored->ask->from);
        self::assertSame('new@example.test', $restored->ask->to);
        self::assertSame('That email is already in use', $restored->error);
    }

    public function testAnUnlinkAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserIdentityUnlinkSignalData(
            7,
            21,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE,
            'key-1',
            'req-1',
            'profile_unlink_identity',
            null,
        );
        $done = UserIdentityUnlinkDoneSignalData::to($ask, null);

        $restored = UserIdentityUnlinkDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(21, $restored->ask->identityId);
    }

    public function testAnAskForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserIdentityUnlinkSignalData(
            0,
            21,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE,
            'key-1',
            null,
            'profile_unlink_identity',
            null,
        );
    }

    public function testAnAnswerWithoutItsAskIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserPasskeyUseDoneSignalData::fromArray([UserPasskeyUseDoneSignalData::error => null]);
    }

    /**
     * @param array<string, mixed> $payload Payload as the sender serialized it
     * @return array<string, mixed> The same payload after a JSON trip
     */
    private static function overTheWire(array $payload): array
    {
        return json_decode(json_encode($payload), true);
    }
}
