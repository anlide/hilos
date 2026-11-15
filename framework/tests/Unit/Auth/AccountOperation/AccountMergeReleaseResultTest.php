<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeReleaseResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountMergeReleaseResultTest extends TestCase
{
    /** The only successful release form carries no account protection data. */
    public function testReleasedRoundtrip(): void
    {
        $payload = AccountMergeReleaseResult::released();
        self::assertSame('{"release":"released"}', $payload->toJson());
        self::assertTrue(AccountMergeReleaseResult::releasedOf($payload));
    }

    /** @return array<string, array{string}> Missing, extra, wrong-value and wrong-type release fields */
    public static function invalidForms(): array
    {
        return [
            'missing' => ['{}'],
            'extra' => ['{"release":"released","extra":true}'],
            'wrong word' => ['{"release":"waiting"}'],
            'null' => ['{"release":null}'],
            'boolean' => ['{"release":true}'],
            'integer' => ['{"release":1}'],
            'array' => ['{"release":[]}'],
            'object' => ['{"release":{}}'],
        ];
    }

    /** @param string $json Invalid saved result */
    #[DataProvider('invalidForms')]
    public function testInvalidReleaseIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeReleaseResult::releasedOf(AccountOperationResultPayload::fromJson($json));
    }

    /** These internal progress words do not extend the participant answer enum. */
    public function testCancellationStateValues(): void
    {
        self::assertSame([
            'WaitingOwners' => 'waiting_owners', 'NotStarted' => 'not_started', 'TooLate' => 'too_late',
        ], array_column(AccountMergeCancellationState::cases(), 'value', 'name'));
    }
}
