<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountErasure;

use Hilos\Auth\AccountErasure\AccountErasureAssignmentPayload;
use Hilos\Auth\AccountErasure\AccountErasureCircle;
use Hilos\Auth\AccountErasure\AccountErasureOutcome;
use Hilos\Auth\AccountErasure\AccountErasureOwnerAddress;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountErasureOperation as EntityAccountErasureOperation;
use Hilos\Database\Entity\Item\AccountErasureAssignment as EntityAccountErasureAssignment;
use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\Object\Item\AccountErasureAssignment as ObjectAccountErasureAssignment;
use Hilos\Database\Object\Collection\AccountErasureAssignments as ObjectAccountErasureAssignments;
use Hilos\Database\Object\Collection\AccountErasureOperations as ObjectAccountErasureOperations;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Unit contracts of the typed values used by account erasure storage. */
final class AccountErasureStorageTest extends TestCase
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
        $circle = AccountErasureCircle::fromArray($circleUserIds, $rootUserId);

        self::assertSame($rootUserId, $circle->rootUserId);
        self::assertSame($circleUserIds, $circle->toArray());
        self::assertSame($circleUserIds, AccountErasureCircle::fromArray($circle->toArray(), $circle->rootUserId)->toArray());
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

        AccountErasureCircle::fromArray($circleUserIds, $rootUserId);
    }

    /** Input references and serialized copies cannot mutate the checked value. */
    public function testCircleDetachesItsIdsFromCallerReferences(): void
    {
        $foldedId = 9;
        $source = [&$foldedId, 7];
        $circle = AccountErasureCircle::fromArray($source, 7);
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
        $address = new AccountErasureOwnerAddress($ownerType, $ownerIndex);

        self::assertSame($ownerType, $address->ownerType);
        self::assertSame($ownerIndex, $address->ownerIndex);
        self::assertTrue($address->equals(new AccountErasureOwnerAddress($ownerType, $ownerIndex)));
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

        new AccountErasureOwnerAddress($ownerType, $ownerIndex);
    }

    /** Owner identity compares both components with case and null preserved. */
    public function testOwnerAddressEqualityIsCaseSensitiveAndDistinguishesNull(): void
    {
        $address = new AccountErasureOwnerAddress('Delivery', 'mail:Shard-A');

        self::assertFalse($address->equals(new AccountErasureOwnerAddress('delivery', 'mail:Shard-A')));
        self::assertFalse($address->equals(new AccountErasureOwnerAddress('Delivery', 'mail:shard-a')));
        self::assertFalse($address->equals(new AccountErasureOwnerAddress('Delivery', 'mail:Shard-A ')));
        self::assertFalse($address->equals(new AccountErasureOwnerAddress('Delivery')));
        self::assertFalse(new AccountErasureOwnerAddress('Delivery')->equals(new AccountErasureOwnerAddress('Delivery', 'null')));
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
        $payload = AccountErasureAssignmentPayload::fromJson($json);

        self::assertSame($json, $payload->toJson());
        self::assertTrue($payload->equals(AccountErasureAssignmentPayload::fromJson($payload->toJson())));
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

        AccountErasureAssignmentPayload::fromJson($json);
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

        AccountErasureAssignmentPayload::fromJson($json);
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
        $leftPayload = AccountErasureAssignmentPayload::fromJson($left);
        $rightPayload = AccountErasureAssignmentPayload::fromJson($right);

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
        ObjectAccountErasureAssignments::validateIdentity($stage, $key);
    }

    public function testExactLimitIdentityKeysAndBoundedPageAreAccepted(): void
    {
        ObjectAccountErasureAssignments::validateIdentity(str_repeat('Ж', 64), str_repeat('界', 191));
        ObjectAccountErasureOperations::validatePage(0, 1);
        ObjectAccountErasureOperations::validatePage(PHP_INT_MAX, ObjectAccountErasureOperations::PAGE_LIMIT_MAX);
        $this->expectException(ValidationException::class);
        ObjectAccountErasureOperations::validatePage(0, ObjectAccountErasureOperations::PAGE_LIMIT_MAX + 1);
    }

    public function testStorageRowsAndSyncDtosPreserveExactIdsNumericMomentsAndNulls(): void
    {
        $operation = EntityAccountErasureOperation::fromRow([
            'id' => 9007199254740993,
            'request_id' => 4294967295,
            'root_user_id' => 2147483648,
            'circle_user_ids' => '[4294967295,2147483648]',
            'stage' => EntityAccountErasureOperation::INITIAL_STAGE,
            'created_at' => -1234567890123,
            'updated_at' => 1999999999999,
            'finished_at' => null,
            'outcome' => null,
        ]);
        $frame = new DbSyncCreatedSignalData(HilosDbContext::accountErasureOperations, (string)$operation->id, $operation->toArray());
        $copy = EntityAccountErasureOperation::fromRow(DbSyncCreatedSignalData::fromArray($frame->toArray())->row);
        self::assertSame($operation->toArray(), $copy->toArray());
        $object = ObjectAccountErasureOperation::fromEntity($copy);
        self::assertSame(9007199254740993, $object->id);
        self::assertSame(-1234567890123, $object->createdAt);
        self::assertSame(1999999999999, $object->updatedAt);
        self::assertNull($object->finishedAt);
        self::assertNull($object->outcome);
        self::assertSame([4294967295, 2147483648], $object->circle()->toArray());
        $updated = new DbSyncUpdatedSignalData(
            HilosDbContext::accountErasureOperations,
            (string)$operation->id,
            ['finished_at' => 2000000000000, 'updated_at' => 2000000000000, 'outcome' => AccountErasureOutcome::Completed->value],
            previous: ['finished_at' => null, 'updated_at' => 1999999999999, 'outcome' => null],
        );
        $updatedCopy = DbSyncUpdatedSignalData::fromArray($updated->toArray());
        self::assertSame($updated->row, $updatedCopy->row);
        self::assertSame($updated->previous, $updatedCopy->previous);
        $object->applyDbSyncEntityUpdate($updatedCopy->row);
        self::assertSame(2000000000000, $object->finishedAt);
        self::assertSame(AccountErasureOutcome::Completed->value, $object->outcome);

        $assignment = EntityAccountErasureAssignment::fromRow([
            'id' => 9007199254740995,
            'operation_id' => 9007199254740993,
            'stage' => EntityAccountErasureOperation::INITIAL_STAGE,
            'assignment_key' => 'one',
            'owner_type' => 'offline-owner',
            'owner_index' => null,
            'user_id' => null,
            'payload_json' => '{}',
            'created_at' => -1234567890123,
        ]);
        $frame = new DbSyncCreatedSignalData(HilosDbContext::accountErasureAssignments, (string)$assignment->id, $assignment->toArray());
        $copy = EntityAccountErasureAssignment::fromRow(DbSyncCreatedSignalData::fromArray($frame->toArray())->row);
        self::assertSame($assignment->toArray(), $copy->toArray());
        $object = ObjectAccountErasureAssignment::fromEntity($copy);
        self::assertSame(9007199254740993, $object->operationId);
        self::assertSame(-1234567890123, $object->createdAt);
        self::assertNull($object->ownerIndex);
        self::assertNull($object->userId);
    }

    public function testBothLazyMountsAndAuthRequirementsExistWithoutDatabaseReads(): void
    {
        $context = new AccountErasureStorageUnitContext();
        $context->configure();
        $mounted = $context->getMountedEntities();
        self::assertSame(EntityAccountErasureOperation::class, $mounted[HilosDbContext::accountErasureOperations]);
        self::assertSame(EntityAccountErasureAssignment::class, $mounted[HilosDbContext::accountErasureAssignments]);
        foreach ([HilosDbContext::accountErasureOperations, HilosDbContext::accountErasureAssignments] as $key) {
            self::assertSame(Objects::LAZY_STRATEGY_KEY, $context->mountedObjectCollection($key)->getLazyStrategy());
        }
        self::assertContains(EntityAccountErasureOperation::_table, new AuthFeature()->requirements()->requiredDbTables);
        self::assertContains(EntityAccountErasureAssignment::_table, new AuthFeature()->requirements()->requiredDbTables);
    }

    /** The backed enum contains only the two terminal storage outcomes. */
    public function testOutcomeBackingValuesAreFixed(): void
    {
        self::assertSame('completed', AccountErasureOutcome::Completed->value);
        self::assertSame('not_started', AccountErasureOutcome::NotStarted->value);
        self::assertSame(AccountErasureOutcome::Completed, AccountErasureOutcome::from('completed'));
        self::assertSame(AccountErasureOutcome::NotStarted, AccountErasureOutcome::from('not_started'));
        self::assertSame([AccountErasureOutcome::Completed, AccountErasureOutcome::NotStarted], AccountErasureOutcome::cases());
        self::assertNull(AccountErasureOutcome::tryFrom('preparing'));
        self::assertNull(AccountErasureOutcome::tryFrom('Completed'));
    }
}

final class AccountErasureStorageUnitContext extends HilosDbContext
{
}
