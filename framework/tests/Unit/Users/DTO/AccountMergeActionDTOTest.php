<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\DTO\AccountMergeActionDTO;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\TestCase;

/** Unit tests for the browser account-merge action payload (HIL-411). */
final class AccountMergeActionDTOTest extends TestCase
{
    public function testItReadsANamedPasswordFateFromTheActionEnvelope(): void
    {
        $dto = AccountMergeActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                AccountMergeActionDTO::survivorUserId => 12,
                AccountMergeActionDTO::loserUserId => 57,
            AccountMergeActionDTO::expectedSurvivorHasSecondFactor => false,
            AccountMergeActionDTO::expectedLoserHasSecondFactor => false,
                AccountMergeActionDTO::passwordFate => PasswordFate::LOSER->value,
            ],
        ]);

        self::assertSame(12, $dto->survivorUserId);
        self::assertSame(57, $dto->loserUserId);
        self::assertSame(PasswordFate::LOSER, $dto->passwordFate);
    }

    public function testAnOmittedPasswordFateStaysUnnamed(): void
    {
        $dto = AccountMergeActionDTO::fromArray([
            AccountMergeActionDTO::survivorUserId => 12,
            AccountMergeActionDTO::loserUserId => 57,
            AccountMergeActionDTO::expectedSurvivorHasSecondFactor => false,
            AccountMergeActionDTO::expectedLoserHasSecondFactor => false,
        ]);

        self::assertNull($dto->passwordFate);
        self::assertArrayNotHasKey(AccountMergeActionDTO::passwordFate, $dto->toArray());
    }

    public function testAnUnknownPasswordFateIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountMergeActionDTO::fromArray([
            AccountMergeActionDTO::survivorUserId => 12,
            AccountMergeActionDTO::loserUserId => 57,
            AccountMergeActionDTO::expectedSurvivorHasSecondFactor => false,
            AccountMergeActionDTO::expectedLoserHasSecondFactor => false,
            AccountMergeActionDTO::passwordFate => 'newest',
        ]);
    }
    public function testTheProtectionChoiceAndBothSnapshotsRoundTrip(): void
    {
        $dto = new AccountMergeActionDTO(12, 57, null, SecondFactorFate::BOTH, false, true);
        $restored = AccountMergeActionDTO::fromArray($dto->toArray());
        self::assertEquals($dto, $restored);
        self::assertSame('both', $dto->toArray()[AccountMergeActionDTO::secondFactorFate]);
    }

    public function testTheProtectionSnapshotCannotBeOmitted(): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeActionDTO::fromArray([
            AccountMergeActionDTO::survivorUserId => 12,
            AccountMergeActionDTO::loserUserId => 57,
        ]);
    }

    public function testAnUnknownProtectionChoiceIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeActionDTO::fromArray([
            AccountMergeActionDTO::survivorUserId => 12,
            AccountMergeActionDTO::loserUserId => 57,
            AccountMergeActionDTO::expectedSurvivorHasSecondFactor => true,
            AccountMergeActionDTO::expectedLoserHasSecondFactor => true,
            AccountMergeActionDTO::secondFactorFate => 'none',
        ]);
    }
}
