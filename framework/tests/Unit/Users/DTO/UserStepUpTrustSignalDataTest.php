<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRevokeSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserStepUpCreditDoneSignalData;
use Hilos\Users\DTO\UserStepUpCreditSignalData;
use Hilos\Users\DTO\UserStepUpRecordDoneSignalData;
use Hilos\Users\DTO\UserStepUpRecordSignalData;
use PHPUnit\Framework\TestCase;

/**
 * The frames that carry a person's step-up confirmations and browser trust to their agent survive the wire whole (HIL-1407).
 *
 * A confirmed operation and the browser it is confirmed in are named together or not at all, and
 * one browser losing its trust is named by a session row.
 */
final class UserStepUpTrustSignalDataTest extends TestCase
{
    private const string SESSION_HASH = 'f0e1d2c3b4a5968778695a4b3c2d1e0f';

    private const string HASH = '$2y$12$abcdefghijklmnopqrstuuJ5bXH3m0ZcJ0y1x2w3v4u5t6s7r8q9p';

    public function testARecordAskAndItsAnswerRoundTrip(): void
    {
        $ask = new UserStepUpRecordSignalData(
            userId: 7,
            sessionTokenHash: self::SESSION_HASH,
            operation: StepUpOperationKey::CHANGE_PASSWORD,
            replySignal: HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            successMessage: null,
        );
        $done = UserStepUpRecordDoneSignalData::to($ask, null);

        $restored = UserStepUpRecordDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(self::SESSION_HASH, $restored->ask->sessionTokenHash);
        self::assertSame(StepUpOperationKey::CHANGE_PASSWORD, $restored->ask->operation);
        self::assertNull($restored->error);
    }

    public function testARecordRefusalKeepsItsWordsAndDetail(): void
    {
        $ask = new UserStepUpRecordSignalData(
            7,
            self::SESSION_HASH,
            StepUpOperationKey::CHANGE_PASSWORD,
            HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            null,
        );
        $done = UserStepUpRecordDoneSignalData::to($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));

        $restored = UserStepUpRecordDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame('DatabaseException', $restored->errorType);
        self::assertSame('disk full', $restored->errorDetail);
        self::assertNull($restored->ask->requestId);
    }

    public function testACreditAndItsRefusalRoundTrip(): void
    {
        $request = new UserStepUpCreditSignalData(
            7,
            self::SESSION_HASH,
            StepUpOperationKey::EXPORT_DATA,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE,
        );
        $done = UserStepUpCreditDoneSignalData::to($request, ActionRefusal::said('This account was merged into another one'));

        $restored = UserStepUpCreditDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        self::assertEquals($done, $restored);
        self::assertSame(StepUpOperationKey::EXPORT_DATA, $restored->request->operation);
        self::assertSame('This account was merged into another one', $restored->error);
        self::assertNull($restored->errorDetail);
    }

    public function testATrustRevokeOfOneBrowserAndOfTheOthersRoundTrip(): void
    {
        foreach ([[41, false], [41, true], [0, true]] as [$sessionId, $others]) {
            $request = new UserBrowserTrustRevokeSignalData(
                7,
                $sessionId,
                $others,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE,
            );
            $done = UserBrowserTrustRevokeDoneSignalData::to($request, null);

            $restored = UserBrowserTrustRevokeDoneSignalData::fromArray(self::overTheWire($done->toArray()));

            self::assertEquals($done, $restored);
            self::assertSame($sessionId, $restored->request->sessionId);
            self::assertSame($others, $restored->request->others);
        }
    }

    public function testOneBrowserLosingTrustIsNamedByASessionRow(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserBrowserTrustRevokeSignalData(7, 0, false, HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE);
    }

    public function testAFrameForNoPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserStepUpCreditSignalData(
            0,
            self::SESSION_HASH,
            StepUpOperationKey::EXPORT_DATA,
            HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE,
        );
    }

    public function testAnAnswerWithoutItsRequestIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserBrowserTrustRevokeDoneSignalData::fromArray([UserBrowserTrustRevokeDoneSignalData::error => null]);
    }

    public function testAPasskeyUseForAStepNamesTheBrowserItConfirms(): void
    {
        $ask = $this->passkeyUse(StepUpOperationKey::CHANGE_PASSWORD, self::SESSION_HASH);

        $restored = UserPasskeyUseSignalData::fromArray(self::overTheWire($ask->toArray()));

        self::assertEquals($ask, $restored);
        self::assertSame(self::SESSION_HASH, $restored->sessionTokenHash);
    }

    public function testAPasskeyUseNamingAnOperationWithoutItsBrowserIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->passkeyUse(StepUpOperationKey::CHANGE_PASSWORD, null);
    }

    public function testAPasskeyUseNamingABrowserWithoutAnOperationIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->passkeyUse(null, self::SESSION_HASH);
    }

    public function testACodeProofForAStepNamesTheBrowserItConfirms(): void
    {
        $ask = $this->codeProof(StepUpOperationKey::CHANGE_PASSWORD, self::SESSION_HASH);

        $restored = UserSecondFactorProveSignalData::fromArray(self::overTheWire($ask->toArray()));

        self::assertEquals($ask, $restored);
        self::assertSame(self::SESSION_HASH, $restored->sessionTokenHash);
    }

    public function testACodeProofNamingAnOperationWithoutItsBrowserIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->codeProof(StepUpOperationKey::CHANGE_PASSWORD, null);
    }

    public function testACodeProofNamingABrowserWithoutAnOperationIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        $this->codeProof(null, self::SESSION_HASH);
    }

    public function testANewPasswordNamesTheBrowserThatKeepsItsTrust(): void
    {
        $reset = new UserPasswordResetSignalData(
            7,
            21,
            self::HASH,
            'ada@example.test',
            41,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE,
            'key-1',
            'req-1',
            'hilos_complete_password_reset',
            null,
        );
        $change = new UserPasswordChangeSignalData(
            7,
            21,
            self::HASH,
            false,
            true,
            0,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
            'key-1',
            'req-1',
            'profile_change_password',
            null,
        );

        $restoredReset = UserPasswordResetSignalData::fromArray(self::overTheWire($reset->toArray()));
        $restoredChange = UserPasswordChangeSignalData::fromArray(self::overTheWire($change->toArray()));

        self::assertEquals($reset, $restoredReset);
        self::assertEquals($change, $restoredChange);
        self::assertSame(41, $restoredReset->keepSessionId);
        self::assertSame(0, $restoredChange->keepSessionId);
    }

    public function testANewPasswordWithoutTheBrowserThatKeepsItsTrustIsRefused(): void
    {
        $payload = new UserPasswordChangeSignalData(
            7,
            21,
            self::HASH,
            false,
            true,
            41,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
            'key-1',
            'req-1',
            'profile_change_password',
            null,
        )->toArray();
        unset($payload[UserPasswordChangeSignalData::keepSessionId]);

        $this->expectException(InvalidFormatException::class);

        UserPasswordChangeSignalData::fromArray($payload);
    }

    /**
     * @param ?string $operation Operation the key confirms, or null for a sign-in
     * @param ?string $sessionTokenHash Browser the operation is confirmed in, or null for a sign-in
     * @return UserPasskeyUseSignalData Passkey use ask
     * @throws InvalidFormatException When only one of the two is named
     */
    private function passkeyUse(?string $operation, ?string $sessionTokenHash): UserPasskeyUseSignalData
    {
        return new UserPasskeyUseSignalData(
            7,
            5,
            13,
            $operation,
            $sessionTokenHash,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            null,
        );
    }

    /**
     * @param ?string $operation Operation the code confirms, or null everywhere else
     * @param ?string $sessionTokenHash Browser the operation is confirmed in, or null everywhere else
     * @return UserSecondFactorProveSignalData Code check ask
     * @throws InvalidFormatException When only one of the two is named
     */
    private function codeProof(?string $operation, ?string $sessionTokenHash): UserSecondFactorProveSignalData
    {
        return new UserSecondFactorProveSignalData(
            7,
            '123456',
            false,
            false,
            false,
            $operation,
            $sessionTokenHash,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
            'key-1',
            null,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            null,
        );
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
