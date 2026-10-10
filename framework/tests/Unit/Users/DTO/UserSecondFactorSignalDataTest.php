<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use PHPUnit\Framework\TestCase;

/** The frames that carry a person's second factor to their agent and back survive the wire whole (HIL-1406). */
final class UserSecondFactorSignalDataTest extends TestCase
{
    private const int LOCK_UNTIL_SEC = 1_790_000_000;

    public function testAProofAskAndAMissThatPutTheLockRoundTrip(): void
    {
        $ask = new UserSecondFactorProveSignalData(
            userId: 7,
            code: '123456',
            backupCode: false,
            cancelReset: true,
            trustDevice: true,
            operation: null,
            sessionTokenHash: null,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR,
            successMessage: null,
        );
        $done = new UserSecondFactorProveDoneSignalData($ask, true, 5, self::LOCK_UNTIL_SEC, false, 'locked', null, null);

        $restored = UserSecondFactorProveDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertTrue($restored->ask->cancelReset);
        self::assertTrue($restored->ask->trustDevice);
        self::assertSame(5, $restored->lockMisses);
        self::assertSame(self::LOCK_UNTIL_SEC, $restored->lockUntil);
    }

    public function testAProofOfAnOperationAndItsRefusalRoundTrip(): void
    {
        $ask = new UserSecondFactorProveSignalData(
            7,
            'ABCD-EFGH',
            true,
            false,
            false,
            'add_authenticator_app',
            'session-hash',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            null,
        );
        $done = UserSecondFactorProveDoneSignalData::refused($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserSecondFactorProveDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('add_authenticator_app', $restored->ask->operation);
        self::assertFalse($restored->missed);
        self::assertNull($restored->lockMisses);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
    }

    public function testAnEnrolmentAskOnTheWayInAndItsAnswerRoundTrip(): void
    {
        $ask = new UserSecondFactorEnrollConfirmSignalData(
            7,
            null,
            '123456',
            'Phone',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE,
            'key-1',
            'req-1',
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM,
            null,
        );
        $done = new UserSecondFactorEnrollConfirmDoneSignalData($ask, false, true, null, null, null);

        $restored = UserSecondFactorEnrollConfirmDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertNull($restored->ask->authenticatorId);
        self::assertTrue($restored->first);
    }

    public function testARemovalAskAndTheFactorSwitchedOffRoundTrip(): void
    {
        $ask = new UserSecondFactorRemoveSignalData(
            7,
            21,
            '123456',
            false,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE,
            'key-1',
            'req-1',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
            null,
        );
        $done = new UserSecondFactorRemoveDoneSignalData($ask, false, null, null, true, null, null, null);

        $restored = UserSecondFactorRemoveDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(21, $restored->ask->authenticatorId);
        self::assertTrue($restored->switchedOff);
    }

    public function testACancelByLinkAndItsAnswerRoundTrip(): void
    {
        $ask = new UserSecondFactorResetCancelSignalData(
            7,
            33,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE,
            'key-1',
            'req-1',
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK,
            null,
        );
        $done = new UserSecondFactorResetCancelDoneSignalData($ask, true, null, null, null);

        $restored = UserSecondFactorResetCancelDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(33, $restored->ask->resetId);
        self::assertTrue($restored->canceled);
    }

    public function testAWaitAskAndItsRefusalRoundTrip(): void
    {
        $ask = new UserSecondFactorWaitWriteSignalData(
            7,
            14,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE,
            'key-1',
            'req-1',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET,
            null,
        );
        $done = UserSecondFactorWaitWriteDoneSignalData::refused($ask, ActionRefusal::said(SecondFactorMessages::REQUIRED));

        $restored = UserSecondFactorWaitWriteDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(14, $restored->ask->days);
        self::assertSame(SecondFactorMessages::REQUIRED, $restored->error);
        self::assertNull($restored->errorType);
    }

    public function testADueRemovalAndItsAnswerRoundTrip(): void
    {
        $request = new UserSecondFactorResetDueSignalData(7, 33, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE);
        $done = new UserSecondFactorResetDueDoneSignalData($request, true, null, null, null);

        $restored = UserSecondFactorResetDueDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertTrue($restored->carriedOut);
    }

    public function testAReminderMarkAndItsAnswerRoundTrip(): void
    {
        $request = new UserSecondFactorResetRemindSignalData(
            7,
            33,
            '2026-10-09 12:00:00',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
        );
        $done = new UserSecondFactorResetRemindDoneSignalData($request, false, null, null, null);

        $restored = UserSecondFactorResetRemindDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('2026-10-09 12:00:00', $restored->request->notifiedBefore);
        self::assertFalse($restored->marked);
    }

    public function testAnUnlockOfACommandAndItsAnswerRoundTrip(): void
    {
        $request = new UserSecondFactorUnlockSignalData(7, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE, 'corr-1');
        $done = new UserSecondFactorUnlockDoneSignalData($request, '2026-10-10 09:00:00', null, null, null);

        $restored = UserSecondFactorUnlockDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('corr-1', $restored->request->correlationId);
        self::assertSame('2026-10-10 09:00:00', $restored->lockedUntil);
    }

    public function testARequestForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserSecondFactorResetDueSignalData(0, 33, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE);
    }

    public function testAnAnswerWithoutItsAskIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserSecondFactorRemoveDoneSignalData::fromArray([UserSecondFactorRemoveDoneSignalData::error => null]);
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
