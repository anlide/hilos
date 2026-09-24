<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\DTO\AccountMergeActionDTO;
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
            AccountMergeActionDTO::passwordFate => 'newest',
        ]);
    }
}
