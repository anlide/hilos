<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\Object\Collection\AccountOperationAssignments as ObjectAccountOperationAssignments;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Unit contracts of the typed values used by account operation storage. */
final class AccountOperationStorageTest extends TestCase
{
    /**
     * @return array<string, array{list<int>, int}> Valid supplied circles and roots
     */
    public static function validCircles(): array
    {
        return [
            'root alone' => [[7], 7],
            'supplied order is not sorted' => [[9, 2, 7], 7],
            'unsigned ids stay exact' => [[4294967295, 2147483648], 2147483648],
        ];
    }

    /**
     * @param list<int> $circleUserIds Supplied account order
     * @param int $rootUserId Final account in that order
     */
    #[DataProvider('validCircles')]
    public function testCirclePreservesOrderAndExactIds(array $circleUserIds, int $rootUserId): void
    {
        $circle = AccountOperationCircle::fromArray($circleUserIds, $rootUserId);

        self::assertSame($rootUserId, $circle->rootUserId);
        self::assertSame($circleUserIds, $circle->toArray());
        self::assertSame($circleUserIds, AccountOperationCircle::fromArray($circle->toArray(), $circle->rootUserId)->toArray());
        foreach ($circleUserIds as $userId) {
            self::assertTrue($circle->contains($userId));
        }
        self::assertFalse($circle->contains(1));
        self::assertFalse($circle->contains(0));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, int}> Invalid circle input and root
     */
    public static function invalidCircles(): array
    {
        return [
            'empty circle' => [[], 7],
            'zero root' => [[7], 0],
            'negative root' => [[7], -7],
            'zero member' => [[0, 7], 7],
            'negative member' => [[-2, 7], 7],
            'numeric string' => [['2', 7], 7],
            'float member' => [[2.0, 7], 7],
            'boolean member' => [[true, 7], 7],
            'null member' => [[null, 7], 7],
            'nested member' => [[[2], 7], 7],
            'duplicate member' => [[2, 2, 7], 7],
            'duplicate root' => [[7, 2, 7], 7],
            'root is absent' => [[2, 9], 7],
            'root is not last' => [[7, 2], 7],
            'sparse array' => [[0 => 2, 2 => 7], 7],
            'associative array' => [['folded' => 2, 'root' => 7], 7],
        ];
    }

    /**
     * @param array<array-key, mixed> $circleUserIds Invalid account order
     * @param int $rootUserId Expected root account
     */
    #[DataProvider('invalidCircles')]
    public function testCircleRefusesInvalidInput(array $circleUserIds, int $rootUserId): void
    {
        $this->expectException(ValidationException::class);

        AccountOperationCircle::fromArray($circleUserIds, $rootUserId);
    }

    /** Input references and serialized copies cannot mutate the checked value. */
    public function testCircleDetachesItsIdsFromCallerReferences(): void
    {
        $foldedId = 9;
        $source = [&$foldedId, 7];
        $circle = AccountOperationCircle::fromArray($source, 7);
        $foldedId = 3;
        $source[] = 10;
        $serialized = $circle->toArray();
        $serialized[0] = 1;

        self::assertSame([9, 7], $circle->toArray());
        self::assertTrue($circle->contains(9));
        self::assertFalse($circle->contains(3));
    }

    /**
     * @return array<string, array{string, ?string}> Literal stable owner addresses
     */
    public static function validOwnerAddresses(): array
    {
        return [
            'unindexed owner' => ['sessionsHolder', null],
            'string zero is an index' => ['library', '0'],
            'channel and shard' => ['delivery', 'mail:Shard-A'],
            'case and whitespace are literal' => [' MissingOwner ', ' SMS:Shard-A '],
            'null word is an index' => ['library', 'null'],
            'maximum ASCII components' => [str_repeat('T', 191), str_repeat('I', 191)],
            'maximum Unicode components' => [str_repeat('Ж', 191), str_repeat('界', 191)],
        ];
    }

    /**
     * @param string $ownerType Stable agent type
     * @param ?string $ownerIndex Literal index or null
     */
    #[DataProvider('validOwnerAddresses')]
    public function testOwnerAddressPreservesLiteralComponents(string $ownerType, ?string $ownerIndex): void
    {
        $address = new AccountOperationOwnerAddress($ownerType, $ownerIndex);

        self::assertSame($ownerType, $address->ownerType);
        self::assertSame($ownerIndex, $address->ownerIndex);
        self::assertTrue($address->equals(new AccountOperationOwnerAddress($ownerType, $ownerIndex)));
    }

    /**
     * @return array<string, array{string, ?string}> Empty or overlong owner components
     */
    public static function invalidOwnerAddresses(): array
    {
        return [
            'empty type' => ['', null],
            'empty index' => ['library', ''],
            'overlong type' => [str_repeat('T', 192), null],
            'overlong index' => ['library', str_repeat('I', 192)],
            'overlong Unicode type' => [str_repeat('Ж', 192), null],
            'overlong Unicode index' => ['library', str_repeat('界', 192)],
        ];
    }

    /**
     * @param string $ownerType Supplied agent type
     * @param ?string $ownerIndex Supplied agent index
     */
    #[DataProvider('invalidOwnerAddresses')]
    public function testOwnerAddressRejectsEmptyOrOverlongComponents(string $ownerType, ?string $ownerIndex): void
    {
        $this->expectException(ValidationException::class);

        new AccountOperationOwnerAddress($ownerType, $ownerIndex);
    }

    /** Owner identity compares both components with case and null preserved. */
    public function testOwnerAddressEqualityIsCaseSensitiveAndDistinguishesNull(): void
    {
        $address = new AccountOperationOwnerAddress('Delivery', 'mail:Shard-A');

        self::assertFalse($address->equals(new AccountOperationOwnerAddress('delivery', 'mail:Shard-A')));
        self::assertFalse($address->equals(new AccountOperationOwnerAddress('Delivery', 'mail:shard-a')));
        self::assertFalse($address->equals(new AccountOperationOwnerAddress('Delivery', 'mail:Shard-A ')));
        self::assertFalse($address->equals(new AccountOperationOwnerAddress('Delivery')));
        self::assertFalse(new AccountOperationOwnerAddress('Delivery')->equals(new AccountOperationOwnerAddress('Delivery', 'null')));
    }

    /**
     * @return array<string, array{string}> Checked JSON objects
     */
    public static function validPayloads(): array
    {
        return [
            'empty object' => ['{}'],
            'whitespace' => [" \n { } \t"],
            'nested values' => ['{"object":{"value":null},"array":[{},[],1,"1",true,false,null]}'],
            'literal representation' => ['{"unicode":"\u0416","fraction":1.00,"escaped":"a\/b"}'],
        ];
    }

    /**
     * @param string $json Valid JSON object
     */
    #[DataProvider('validPayloads')]
    public function testPayloadRoundTripPreservesItsJson(string $json): void
    {
        $payload = AccountOperationAssignmentPayload::fromJson($json);

        self::assertSame($json, $payload->toJson());
        self::assertTrue($payload->equals(AccountOperationAssignmentPayload::fromJson($payload->toJson())));
    }

    /**
     * @return array<string, array{string}> Malformed JSON input
     */
    public static function malformedPayloads(): array
    {
        return [
            'empty input' => [''],
            'missing brace' => ['{"value":1'],
            'trailing comma' => ['{"value":1,}'],
            'unquoted key' => ['{value:1}'],
            'two objects' => ['{}{}'],
            'invalid UTF-8' => ["{\"value\":\"\xFF\"}"],
        ];
    }

    /**
     * @param string $json Malformed JSON
     */
    #[DataProvider('malformedPayloads')]
    public function testPayloadRejectsMalformedJson(string $json): void
    {
        $this->expectException(InvalidJsonException::class);

        AccountOperationAssignmentPayload::fromJson($json);
    }

    /**
     * @return array<string, array{string}> Valid JSON roots that are not objects
     */
    public static function nonObjectPayloads(): array
    {
        return [
            'empty array' => ['[]'],
            'array of objects' => ['[{}]'],
            'string' => ['"{}"'],
            'integer' => ['1'],
            'boolean' => ['false'],
            'null' => ['null'],
        ];
    }

    /**
     * @param string $json JSON with a non-object root
     */
    #[DataProvider('nonObjectPayloads')]
    public function testPayloadRequiresAnObjectRoot(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountOperationAssignmentPayload::fromJson($json);
    }

    /**
     * @return array<string, array{string, string, bool}> Semantic JSON equality cases
     */
    public static function payloadComparisons(): array
    {
        return [
            'empty objects' => ['{}', '{ }', true],
            'object key order' => ['{"a":1,"b":2}', '{"b":2,"a":1}', true],
            'nested object key order' => [
                '{"x":{"a":1,"b":null},"y":[{"c":true,"d":[]}]}',
                '{"y":[{"d":[],"c":true}],"x":{"b":null,"a":1}}',
                true,
            ],
            'string escaping' => ['{"x":"\u0041"}', '{"x":"A"}', true],
            'number spelling' => ['{"x":1}', '{"x":1.0e0}', true],
            'adjacent large integers' => ['{"x":9223372036854775808}', '{"x":9223372036854775809}', false],
            'large integer and string' => ['{"x":9223372036854775808}', '{"x":"9223372036854775808"}', false],
            'precise fractions' => ['{"x":0.12345678901234567891}', '{"x":0.12345678901234567892}', false],
            'large exponent values' => ['{"x":1e999}', '{"x":2e999}', false],
            'tiny values' => ['{"x":1e-999}', '{"x":2e-999}', false],
            'fraction and exponent normalization' => ['{"x":123.4500}', '{"x":12345e-2}', true],
            'negative zero' => ['{"x":-0.00e999}', '{"x":0}', true],
            'large exponent normalization' => ['{"x":10e99999999999999999999}', '{"x":1e100000000000000000000}', true],
            'negative exponent normalization' => ['{"x":10e-100000000000000000000}', '{"x":1e-99999999999999999999}', true],
            'exponent shift crosses zero' => ['{"x":100e-1}', '{"x":1e1}', true],
            'fraction shift crosses zero' => ['{"x":0.001e1}', '{"x":1e-2}', true],
            'array order' => ['{"x":[1,2]}', '{"x":[2,1]}', false],
            'nested array order' => ['{"x":[[1,2]]}', '{"x":[[2,1]]}', false],
            'array and object' => ['{"x":[]}', '{"x":{}}', false],
            'numeric keys remain object keys' => ['{"x":{"0":1}}', '{"x":[1]}', false],
            'missing and null' => ['{}', '{"x":null}', false],
            'different null key' => ['{"a":null}', '{"b":null}', false],
            'string and number' => ['{"x":"1"}', '{"x":1}', false],
            'boolean and number' => ['{"x":true}', '{"x":1}', false],
            'boolean and null' => ['{"x":false}', '{"x":null}', false],
            'string case' => ['{"x":"A"}', '{"x":"a"}', false],
            'object key case' => ['{"A":1}', '{"a":1}', false],
            'array length' => ['{"x":[1]}', '{"x":[1,1]}', false],
        ];
    }

    /**
     * @param string $left First JSON object
     * @param string $right Second JSON object
     * @param bool $equal Expected semantic equality
     */
    #[DataProvider('payloadComparisons')]
    public function testPayloadEqualityUsesJsonSemantics(string $left, string $right, bool $equal): void
    {
        $leftPayload = AccountOperationAssignmentPayload::fromJson($left);
        $rightPayload = AccountOperationAssignmentPayload::fromJson($right);

        self::assertSame($equal, $leftPayload->equals($rightPayload));
        self::assertSame($equal, $rightPayload->equals($leftPayload));
    }

    /**
     * @return iterable<string, array{string, string}> Invalid literal identity components
     */
    public static function invalidIdentities(): iterable
    {
        yield 'empty stage' => ['', 'key'];
        yield 'long stage' => [str_repeat('Ж', 65), 'key'];
        yield 'empty key' => ['preparing', ''];
        yield 'long key' => ['preparing', str_repeat('界', 192)];
    }

    /**
     * @param string $stage Invalid stage or a valid stage beside an invalid key
     * @param string $key Invalid key or a valid key beside an invalid stage
     */
    #[DataProvider('invalidIdentities')]
    public function testIdentityStorageLimitsAreValidated(string $stage, string $key): void
    {
        $this->expectException(ValidationException::class);
        ObjectAccountOperationAssignments::validateIdentity($stage, $key);
    }

    public function testExactLimitIdentityKeysAndBoundedPageAreAccepted(): void
    {
        ObjectAccountOperationAssignments::validateIdentity(str_repeat('Ж', 64), str_repeat('界', 191));
        ObjectAccountOperations::validatePage(0, 1);
        ObjectAccountOperations::validatePage(PHP_INT_MAX, ObjectAccountOperations::PAGE_LIMIT_MAX);
        $this->expectException(ValidationException::class);
        ObjectAccountOperations::validatePage(0, ObjectAccountOperations::PAGE_LIMIT_MAX + 1);
    }

    public function testStorageRowsAndSyncDtosPreserveExactIdsNumericMomentsAndNulls(): void
    {
        $operation = EntityAccountOperation::fromRow([
            'id' => 9007199254740993,
            'kind' => AccountOperationKind::Erasure->value,
            'request_id' => 4294967295,
            'root_user_id' => 2147483648,
            'circle_user_ids' => '[4294967295,2147483648]',
            'stage' => EntityAccountOperation::INITIAL_STAGE,
            'created_at' => -1234567890123,
            'updated_at' => 1999999999999,
            'finished_at' => null,
            'outcome' => null,
        ]);
        $frame = new DbSyncCreatedSignalData(HilosDbContext::accountOperations, (string)$operation->id, $operation->toArray());
        $copy = EntityAccountOperation::fromRow(DbSyncCreatedSignalData::fromArray($frame->toArray())->row);
        self::assertSame($operation->toArray(), $copy->toArray());
        $object = ObjectAccountOperation::fromEntity($copy);
        self::assertSame(9007199254740993, $object->id);
        self::assertSame(-1234567890123, $object->createdAt);
        self::assertSame(1999999999999, $object->updatedAt);
        self::assertNull($object->finishedAt);
        self::assertNull($object->outcome);
        self::assertSame([4294967295, 2147483648], $object->circle()->toArray());
        $updated = new DbSyncUpdatedSignalData(
            HilosDbContext::accountOperations,
            (string)$operation->id,
            ['finished_at' => 2000000000000, 'updated_at' => 2000000000000, 'outcome' => AccountOperationOutcome::Completed->value],
            previous: ['finished_at' => null, 'updated_at' => 1999999999999, 'outcome' => null],
        );
        $updatedCopy = DbSyncUpdatedSignalData::fromArray($updated->toArray());
        self::assertSame($updated->row, $updatedCopy->row);
        self::assertSame($updated->previous, $updatedCopy->previous);
        $object->applyDbSyncEntityUpdate($updatedCopy->row);
        self::assertSame(2000000000000, $object->finishedAt);
        self::assertSame(AccountOperationOutcome::Completed->value, $object->outcome);

        $assignment = EntityAccountOperationAssignment::fromRow([
            'id' => 9007199254740995,
            'operation_id' => 9007199254740993,
            'stage' => EntityAccountOperation::INITIAL_STAGE,
            'assignment_key' => 'one',
            'owner_type' => 'offline-owner',
            'owner_index' => null,
            'user_id' => null,
            'payload_json' => '{}',
            'created_at' => -1234567890123,
        ]);
        $frame = new DbSyncCreatedSignalData(HilosDbContext::accountOperationAssignments, (string)$assignment->id, $assignment->toArray());
        $copy = EntityAccountOperationAssignment::fromRow(DbSyncCreatedSignalData::fromArray($frame->toArray())->row);
        self::assertSame($assignment->toArray(), $copy->toArray());
        $object = ObjectAccountOperationAssignment::fromEntity($copy);
        self::assertSame(9007199254740993, $object->operationId);
        self::assertSame(-1234567890123, $object->createdAt);
        self::assertNull($object->ownerIndex);
        self::assertNull($object->userId);
    }

    public function testBothLazyMountsAndAuthRequirementsExistWithoutDatabaseReads(): void
    {
        $context = new AccountOperationStorageUnitContext();
        $context->configure();
        $mounted = $context->getMountedEntities();
        self::assertSame(EntityAccountOperation::class, $mounted[HilosDbContext::accountOperations]);
        self::assertSame(EntityAccountOperationAssignment::class, $mounted[HilosDbContext::accountOperationAssignments]);
        foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments] as $key) {
            self::assertSame(Objects::LAZY_STRATEGY_KEY, $context->mountedObjectCollection($key)->getLazyStrategy());
        }
        self::assertContains(EntityAccountOperation::_table, new AuthFeature()->requirements()->requiredDbTables);
        self::assertContains(EntityAccountOperationAssignment::_table, new AuthFeature()->requirements()->requiredDbTables);
    }

    public function testCommonStorageMetadataAndProtocolKeysAreFixed(): void
    {
        self::assertSame([
            'id', 'kind', 'request_id', 'root_user_id', 'loser_user_id', 'merge_choice', 'circle_user_ids',
            'stage', 'created_at', 'updated_at', 'finished_at', 'open_loser_user_id', 'outcome', 'final_refusals',
        ], EntityAccountOperation::_columns);
        self::assertSame(PhpType::STRING->value, EntityAccountOperation::_types[EntityAccountOperation::kind]);
        self::assertSame(PhpType::INTEGER->value, EntityAccountOperation::_types[EntityAccountOperation::open_loser_user_id]);
        self::assertSame(PhpType::JSON->value, EntityAccountOperation::_types[EntityAccountOperation::final_refusals]);
        self::assertSame([
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => ['open_loser_user_id'],
        ], EntityAccountOperation::_indexes['uq_account_operation_open_loser']);
        self::assertSame(['kind', 'finished_at', 'id'],
            EntityAccountOperation::_indexes['idx_account_operation_open'][Entity::INDEX_COLUMNS]);
        self::assertSame(['Erasure' => 'erasure', 'Merge' => 'merge'], array_column(AccountOperationKind::cases(), 'value', 'name'));
        self::assertSame(['Preparing' => 'preparing', 'Go' => 'go', 'Cancelling' => 'cancelling'],
            array_column(AccountMergeStage::cases(), 'value', 'name'));
        self::assertSame(['PrepareSurvivor' => 'prepare_survivor', 'PrepareLoser' => 'prepare_loser', 'Give' => 'give'],
            array_column(AccountMergeDuty::cases(), 'value', 'name'));
    }

    public function testMergeFieldsAreTypedOnObjectAndViewWithoutExposingTheOpenSlot(): void
    {
        $choices = new AccountMergeChoice(null, null, false, true);
        $entity = EntityAccountOperation::fromRow([
            'id' => 1,
            'kind' => AccountOperationKind::Merge->value,
            'request_id' => null,
            'root_user_id' => 7,
            'loser_user_id' => 9,
            'merge_choice' => $choices->toJson(),
            'circle_user_ids' => '[9,7]',
            'stage' => AccountMergeStage::Preparing->value,
            'created_at' => 1234,
            'updated_at' => 1234,
            'finished_at' => null,
            'open_loser_user_id' => 9,
            'outcome' => null,
        ]);
        $object = ObjectAccountOperation::fromEntity($entity);
        foreach ([$object, new AccountOperation($object)] as $operation) {
            self::assertSame(AccountOperationKind::Merge, $operation->kind);
            self::assertNull($operation->requestId);
            self::assertSame(9, $operation->loserUserId);
            self::assertSame(7, $operation->survivorUserId);
            self::assertTrue($choices->equals($operation->mergeChoice));
            self::assertArrayNotHasKey('openLoserUserId', $operation->toArray());
            self::assertArrayNotHasKey('open_loser_user_id', $operation->toArray());
        }
    }

    /** @return iterable<string, array{AccountOperationKind, ?string}> Refused stored merge choices */
    public static function invalidStoredMergeChoices(): iterable
    {
        yield 'erasure' => [AccountOperationKind::Erasure, null];
        yield 'missing merge choices' => [AccountOperationKind::Merge, null];
        yield 'invalid JSON' => [AccountOperationKind::Merge, '{'];
        yield 'invalid shape' => [AccountOperationKind::Merge, '[]'];
    }

    #[DataProvider('invalidStoredMergeChoices')]
    public function testMergeChoiceRefusesErasureAndMalformedStoredData(AccountOperationKind $kind, ?string $json): void
    {
        $entity = new EntityAccountOperation();
        $entity->kind = $kind->value;
        $entity->merge_choice = $json;
        $object = ObjectAccountOperation::fromEntity($entity);
        $this->expectException(ValidationException::class);

        new AccountOperation($object)->mergeChoice;
    }

    /** The backed enum contains only the two terminal storage outcomes. */
    public function testOutcomeBackingValuesAreFixed(): void
    {
        self::assertSame('completed', AccountOperationOutcome::Completed->value);
        self::assertSame('not_started', AccountOperationOutcome::NotStarted->value);
        self::assertSame(AccountOperationOutcome::Completed, AccountOperationOutcome::from('completed'));
        self::assertSame(AccountOperationOutcome::NotStarted, AccountOperationOutcome::from('not_started'));
        self::assertSame([AccountOperationOutcome::Completed, AccountOperationOutcome::NotStarted], AccountOperationOutcome::cases());
        self::assertNull(AccountOperationOutcome::tryFrom('preparing'));
        self::assertNull(AccountOperationOutcome::tryFrom('Completed'));
    }
}

final class AccountOperationStorageUnitContext extends HilosDbContext
{
}
