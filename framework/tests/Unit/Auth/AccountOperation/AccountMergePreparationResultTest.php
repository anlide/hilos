<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Literal preparation formats, strict saved-result reading and refusal priority (HIL-1988). */
final class AccountMergePreparationResultTest extends TestCase
{
    /** Reservation preserves the optional password and normalizes confirmed factor ids only when writing. */
    public function testReservedPayloadIsLiteralAndReadsBack(): void
    {
        $payload = AccountMergePreparationResult::reserved(7, [9, 3, 9, 4]);
        self::assertSame('{"prepare":"reserved","passwordIdentityId":7,"secondFactorIds":[3,4,9]}', $payload->toJson());

        $result = AccountMergePreparationResult::of($payload);
        self::assertTrue($result->isReserved());
        self::assertNull($result->refusal);
        self::assertSame(7, $result->passwordIdentityId);
        self::assertSame([3, 4, 9], $result->secondFactorIds);

        $empty = AccountMergePreparationResult::reserved(null, []);
        self::assertSame('{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[]}', $empty->toJson());
        $emptyResult = AccountMergePreparationResult::of($empty);
        self::assertTrue($emptyResult->isReserved());
        self::assertNull($emptyResult->refusal);
        self::assertNull($emptyResult->passwordIdentityId);
        self::assertSame([], $emptyResult->secondFactorIds);
    }

    /** Refusal carries a code and never includes reserved identities. */
    public function testRefusedPayloadIsLiteralAndReadsBack(): void
    {
        $payload = AccountMergePreparationResult::refused(AccountMergeRefusal::Occupied);
        self::assertSame('{"prepare":"refused","refusal":"occupied"}', $payload->toJson());

        $result = AccountMergePreparationResult::of($payload);
        self::assertFalse($result->isReserved());
        self::assertSame(AccountMergeRefusal::Occupied, $result->refusal);
        self::assertNull($result->passwordIdentityId);
        self::assertSame([], $result->secondFactorIds);
    }

    /** Object-key order does not alter either form's meaning. */
    public function testSavedObjectKeysMayBeReordered(): void
    {
        $reserved = AccountMergePreparationResult::of(AccountOperationResultPayload::fromJson(
            '{"secondFactorIds":[2,8],"passwordIdentityId":1,"prepare":"reserved"}',
        ));
        self::assertTrue($reserved->isReserved());
        self::assertSame(1, $reserved->passwordIdentityId);
        self::assertSame([2, 8], $reserved->secondFactorIds);

        $refused = AccountMergePreparationResult::of(AccountOperationResultPayload::fromJson(
            '{"refusal":"missing","prepare":"refused"}',
        ));
        self::assertFalse($refused->isReserved());
        self::assertSame(AccountMergeRefusal::Missing, $refused->refusal);
    }

    /** @return array<string, array{string}> Saved objects outside the two approved forms */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'unknown preparation' => ['{"prepare":"ready","passwordIdentityId":null,"secondFactorIds":[]}'],
            'missing preparation' => ['{"passwordIdentityId":null,"secondFactorIds":[]}'],
            'null preparation' => ['{"prepare":null,"passwordIdentityId":null,"secondFactorIds":[]}'],
            'boolean preparation' => ['{"prepare":true,"passwordIdentityId":null,"secondFactorIds":[]}'],
            'integer preparation' => ['{"prepare":1,"passwordIdentityId":null,"secondFactorIds":[]}'],
            'object preparation' => ['{"prepare":{},"passwordIdentityId":null,"secondFactorIds":[]}'],
            'missing password' => ['{"prepare":"reserved","secondFactorIds":[]}'],
            'missing factors' => ['{"prepare":"reserved","passwordIdentityId":null}'],
            'extra reserved key' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[],"extra":1}'],
            'refusal in reservation' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[],"refusal":"missing"}'],
            'zero password' => ['{"prepare":"reserved","passwordIdentityId":0,"secondFactorIds":[]}'],
            'negative password' => ['{"prepare":"reserved","passwordIdentityId":-1,"secondFactorIds":[]}'],
            'string password' => ['{"prepare":"reserved","passwordIdentityId":"1","secondFactorIds":[]}'],
            'float password' => ['{"prepare":"reserved","passwordIdentityId":1.0,"secondFactorIds":[]}'],
            'boolean password' => ['{"prepare":"reserved","passwordIdentityId":true,"secondFactorIds":[]}'],
            'array password' => ['{"prepare":"reserved","passwordIdentityId":[],"secondFactorIds":[]}'],
            'object password' => ['{"prepare":"reserved","passwordIdentityId":{},"secondFactorIds":[]}'],
            'null factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":null}'],
            'string factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":"1"}'],
            'integer factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":1}'],
            'boolean factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":false}'],
            'object factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":{}}'],
            'numeric-key object factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":{"0":1}}'],
            'zero factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[0]}'],
            'negative factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[-1]}'],
            'string factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":["1"]}'],
            'float factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[1.0]}'],
            'boolean factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[true]}'],
            'null factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[null]}'],
            'array factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[[]]}'],
            'object factor' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[{}]}'],
            'duplicate factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[2,2]}'],
            'descending factors' => ['{"prepare":"reserved","passwordIdentityId":null,"secondFactorIds":[3,2]}'],
            'missing refusal' => ['{"prepare":"refused"}'],
            'unknown refusal' => ['{"prepare":"refused","refusal":"unavailable"}'],
            'null refusal' => ['{"prepare":"refused","refusal":null}'],
            'boolean refusal' => ['{"prepare":"refused","refusal":false}'],
            'integer refusal' => ['{"prepare":"refused","refusal":1}'],
            'array refusal' => ['{"prepare":"refused","refusal":[]}'],
            'object refusal' => ['{"prepare":"refused","refusal":{}}'],
            'extra refused key' => ['{"prepare":"refused","refusal":"missing","extra":1}'],
            'password in refusal' => ['{"prepare":"refused","refusal":"missing","passwordIdentityId":null}'],
            'factors in refusal' => ['{"prepare":"refused","refusal":"missing","secondFactorIds":[]}'],
        ];
    }

    /** @param string $json Saved preparation of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $payload = AccountOperationResultPayload::fromJson($json);
        $this->expectException(InvalidFormatException::class);

        AccountMergePreparationResult::of($payload);
    }

    /** @return array<string, array{?int, array}> Invalid input ids must not become a reservation */
    public static function invalidReservationIds(): array
    {
        return [
            'zero password' => [0, []],
            'negative password' => [-1, []],
            'zero factor' => [null, [0]],
            'negative factor' => [null, [-1]],
            'string factor' => [null, ['1']],
            'float factor' => [null, [1.0]],
            'boolean factor' => [null, [true]],
            'null factor' => [null, [null]],
            'array factor' => [null, [[]]],
            'non-list factors' => [null, [1 => 2]],
        ];
    }

    /**
     * @param ?int $passwordIdentityId Invalid password id, or null for an absent password
     * @param array $secondFactorIds Invalid factor ids, or an empty list
     */
    #[DataProvider('invalidReservationIds')]
    public function testInvalidReservationIdsAreRefused(?int $passwordIdentityId, array $secondFactorIds): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountMergePreparationResult::reserved($passwordIdentityId, $secondFactorIds);
    }

    /** Priority, stable codes and all refusal templates are literal contracts. */
    public function testRefusalPriorityCodesAndTemplatesAreLiteral(): void
    {
        self::assertSame([
            ['missing', 'No such user: %d'],
            ['survivor_merged', '%s was itself merged into %s'],
            ['loser_merged', '%s was already merged into %s'],
            ['being_deleted', '%s is being deleted'],
            ['occupied', '%s is already being merged'],
            ['protection_changed', 'Second-factor protection changed. Review the accounts and choose again.'],
            ['second_factor_fate_required', 'Choose second-factor protection: survivor or both (--second-factor on the console).'],
            ['loser_without_second_factor', 'The loser has no confirmed authenticator. Remove the second-factor choice.'],
            ['survivor_without_second_factor', "The survivor has no confirmed authenticator. Transfer the loser's protection or cancel."],
            ['loser_pending_removal', 'The loser has a pending second-factor removal. Cancel it or wait for it to finish.'],
            ['survivor_pending_removal', 'The survivor has a pending second-factor removal. Cancel it or wait for it to finish.'],
        ], array_map(
            static fn(AccountMergeRefusal $refusal): array => [$refusal->value, $refusal->template()],
            AccountMergeRefusal::cases(),
        ));
    }
}
