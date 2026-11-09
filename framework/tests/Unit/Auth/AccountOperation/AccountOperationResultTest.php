<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationJsonObject;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\AccountOperationResults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Unit contracts of participant result JSON and agent addressing. */
final class AccountOperationResultTest extends TestCase
{
    /** Refusal values are stable domain reasons, independent of future wire names. */
    public function testParticipantStepRefusalValues(): void
    {
        self::assertSame([
            'NoParticipant' => 'no_participant',
            'UnknownAssignment' => 'unknown_assignment',
            'ForeignAssignment' => 'foreign_assignment',
            'ClosedStage' => 'closed_stage',
        ], array_column(AccountOperationStepRefusal::cases(), 'value', 'name'));
    }

    /** Inherited factories build each concrete payload through the common JSON boundary. */
    public function testPayloadFactoriesPreserveTheirConcreteTypes(): void
    {
        $assignment = AccountOperationAssignmentPayload::fromJson('{}');
        $result = AccountOperationResultPayload::fromJson('{}');

        self::assertInstanceOf(AccountOperationAssignmentPayload::class, $assignment);
        self::assertInstanceOf(AccountOperationResultPayload::class, $result);
        self::assertInstanceOf(AccountOperationJsonObject::class, $assignment);
        self::assertInstanceOf(AccountOperationJsonObject::class, $result);
    }

    /** @return array<string, array{string}> Valid result objects and their original representation */
    public static function validPayloads(): array
    {
        return [
            'empty object' => ['{}'],
            'whitespace' => [" \n { } \t"],
            'nested values' => ['{"object":{"value":null},"array":[{},[],1,"1",true,false,null]}'],
            'escaped strings' => ['{"unicode":"\u0416","escaped":"a\/b","quoted":"\\""}'],
            'exact numbers' => ['{"integer":9223372036854775809,"fraction":0.12345678901234567891,"exponent":1e99999999999999999999}'],
        ];
    }

    /** @param string $json Checked object with representation to preserve */
    #[DataProvider('validPayloads')]
    public function testResultPayloadRoundTripPreservesOriginalJson(string $json): void
    {
        $payload = AccountOperationResultPayload::fromJson($json);

        self::assertSame($json, $payload->toJson());
        self::assertTrue($payload->equals(AccountOperationResultPayload::fromJson($payload->toJson())));
    }

    /** @return array<string, array{string}> Malformed result JSON */
    public static function malformedPayloads(): array
    {
        return [
            'empty input' => [''],
            'missing brace' => ['{"value":1'],
            'trailing comma' => ['{"value":1,}'],
            'unquoted key' => ['{value:1}'],
            'two objects' => ['{}{}'],
            'invalid number' => ['{"value":01}'],
            'invalid UTF-8' => ["{\"value\":\"\xFF\"}"],
        ];
    }

    /** @param string $json Malformed JSON to refuse */
    #[DataProvider('malformedPayloads')]
    public function testResultPayloadRejectsMalformedJson(string $json): void
    {
        $this->expectException(InvalidJsonException::class);

        AccountOperationResultPayload::fromJson($json);
    }

    /** @return array<string, array{string}> Valid JSON with a non-object root */
    public static function nonObjectPayloads(): array
    {
        return [
            'empty array' => ['[]'],
            'array of objects' => ['[{}]'],
            'string' => ['"{}"'],
            'integer' => ['1'],
            'fraction' => ['1.5'],
            'true' => ['true'],
            'false' => ['false'],
            'null' => ['null'],
        ];
    }

    /** @param string $json Non-object JSON to refuse */
    #[DataProvider('nonObjectPayloads')]
    public function testResultPayloadRequiresAnObjectRoot(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountOperationResultPayload::fromJson($json);
    }

    /** @return array<string, array{string, string, bool}> Exact semantic equality cases */
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
            'escaped keys and strings' => ['{"\u0041":"\u0042"}', '{"A":"B"}', true],
            'number spelling' => ['{"x":1}', '{"x":1.0e0}', true],
            'fraction normalization' => ['{"x":123.4500}', '{"x":12345e-2}', true],
            'negative zero' => ['{"x":-0.00e999}', '{"x":0}', true],
            'huge positive exponent' => ['{"x":10e99999999999999999999}', '{"x":1e100000000000000000000}', true],
            'huge negative exponent' => ['{"x":10e-100000000000000000000}', '{"x":1e-99999999999999999999}', true],
            'adjacent large integers' => ['{"x":9223372036854775808}', '{"x":9223372036854775809}', false],
            'large integer and string' => ['{"x":9223372036854775808}', '{"x":"9223372036854775808"}', false],
            'precise fractions' => ['{"x":0.12345678901234567891}', '{"x":0.12345678901234567892}', false],
            'overflow values differ' => ['{"x":1e999}', '{"x":2e999}', false],
            'underflow values differ' => ['{"x":1e-999}', '{"x":2e-999}', false],
            'array order' => ['{"x":[1,2]}', '{"x":[2,1]}', false],
            'nested array order' => ['{"x":[[1,2]]}', '{"x":[[2,1]]}', false],
            'array length' => ['{"x":[1]}', '{"x":[1,1]}', false],
            'array and object' => ['{"x":[]}', '{"x":{}}', false],
            'numeric object keys' => ['{"x":{"0":1}}', '{"x":[1]}', false],
            'missing and null' => ['{}', '{"x":null}', false],
            'different null key' => ['{"a":null}', '{"b":null}', false],
            'string and number' => ['{"x":"1"}', '{"x":1}', false],
            'string and boolean' => ['{"x":"true"}', '{"x":true}', false],
            'boolean and number' => ['{"x":true}', '{"x":1}', false],
            'boolean and null' => ['{"x":false}', '{"x":null}', false],
            'string case' => ['{"x":"A"}', '{"x":"a"}', false],
            'object key case' => ['{"A":1}', '{"a":1}', false],
        ];
    }

    /**
     * @param string $left First result object
     * @param string $right Second result object
     * @param bool $equal Expected semantic equality
     */
    #[DataProvider('payloadComparisons')]
    public function testResultPayloadEqualityUsesJsonSemantics(string $left, string $right, bool $equal): void
    {
        $leftPayload = AccountOperationResultPayload::fromJson($left);
        $rightPayload = AccountOperationResultPayload::fromJson($right);

        self::assertSame($equal, $leftPayload->equals($rightPayload));
        self::assertSame($equal, $rightPayload->equals($leftPayload));
    }

    /** @return array<string, array{?string, string}> Literal indexed and singleton agent identities */
    public static function ownerIdentities(): array
    {
        return [
            'singleton' => [null, 'result_test_owner'],
            'numeric index' => ['7', 'result_test_owner:7'],
            'zero index' => ['0', 'result_test_owner:0'],
            'index contains separator' => ['mail:Shard-A', 'result_test_owner:mail:Shard-A'],
            'literal whitespace and case' => [' SMS:Shard-A ', 'result_test_owner: SMS:Shard-A '],
            'Unicode index' => ['Ж:界', 'result_test_owner:Ж:界'],
            'null word is an index' => ['null', 'result_test_owner:null'],
        ];
    }

    /**
     * @param ?string $ownerIndex Optional literal agent index
     * @param string $expectedId Expected canonical agent identity
     */
    #[DataProvider('ownerIdentities')]
    public function testOwnerAddressMatchesAgentIdentityAndUsesItsParser(?string $ownerIndex, string $expectedId): void
    {
        $agent = new AccountOperationResultTestAgent($ownerIndex);
        $address = new AccountOperationOwnerAddress($agent->getType(), $ownerIndex);

        self::assertSame($expectedId, $address->agentId());
        self::assertSame($agent->getId(), $address->agentId());
        $parsed = AgentId::fromId($address->agentId());
        self::assertSame($address->ownerType, $parsed->type);
        self::assertSame($address->ownerIndex, $parsed->index);
    }

    /** Metadata keeps the participant's string set separate from the cascading parent. */
    public function testResultMetadataDeclaresItsOwnerSetAndWholeTablePurge(): void
    {
        self::assertSame('owner_agent_id', EntityAccountOperationResult::_setVia);
        self::assertFalse(EntityAccountOperationResult::_setRoot);
        self::assertSame(['assignment_id' => EntityAccountOperationAssignment::_table], EntityAccountOperationResult::_foreign);
        self::assertSame(AnonymizationStrategy::PURGE, EntityAccountOperationResult::_pii);
        self::assertFalse(EntityAccountOperationResult::_journaled);
        self::assertSame(['created_at'], EntityAccountOperationResult::_moments);
        self::assertFalse(defined(EntityAccountOperationResult::class . '::_piiNotPersonal'));
        $sql = file_get_contents(__DIR__ . '/../../../../backend/Database/Migration/Stub/create_hilos_account_operation_result.sql');
        self::assertStringContainsString('ON DELETE CASCADE ON UPDATE RESTRICT', $sql);
        self::assertStringContainsString('VARCHAR(383) COLLATE utf8mb4_bin NOT NULL', $sql);
        self::assertStringContainsString('`created_at` BIGINT NOT NULL', $sql);
    }

    /** AUTH selects the table and the standard context mounts its complete lazy chain. */
    public function testResultMountAndAuthRequirementExistWithoutDatabaseReads(): void
    {
        $context = new AccountOperationResultUnitContext();
        $context->configure();
        self::assertSame(EntityAccountOperationResult::class, $context->getMountedEntities()[HilosDbContext::accountOperationResults]);
        self::assertInstanceOf(AccountOperationResults::class, $context->accountOperationResults);
        self::assertSame(Objects::LAZY_STRATEGY_KEY, $context->mountedObjectCollection(HilosDbContext::accountOperationResults)->getLazyStrategy());
        self::assertContains(EntityAccountOperationResult::_table, new AuthFeature()->requirements()->requiredDbTables);
    }
}

final class AccountOperationResultUnitContext extends HilosDbContext
{
}

/** Agent fixture using the production getId() implementation. */
final class AccountOperationResultTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'result_test_owner';

    /** @param ?string $index Optional literal instance index */
    public function __construct(?string $index)
    {
        $this->agentIndex = $index;
    }

    /** No resources are opened by this identity fixture. */
    public function onStop(): void
    {
    }
}
