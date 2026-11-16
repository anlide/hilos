<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeBackupCodeRow;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeIdentityRow;
use Hilos\Auth\AccountOperation\AccountMergePasskeyRow;
use Hilos\Auth\AccountOperation\AccountMergeSecondFactorRow;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Entity\Item\AccountMergeCopy as EntityAccountMergeCopy;
use Hilos\Database\Object\Item\AccountMergeCopy as ObjectAccountMergeCopy;
use Hilos\Database\View\Item\AccountMergeCopy as DbAccountMergeCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Independent boundary contract for the private copy and the public give fact. */
final class AccountMergeHandoverCopyTest extends TestCase
{
    private const string COPY_JSON = <<<'JSON'
{"operationId":2,"assignmentId":3,"loserUserId":10,"survivorUserId":20,
 "loserPasswordIdentityId":31,"survivorPasswordIdentityId":7,"effectiveWaitDays":14,
 "identities":[
  {"id":31,"userId":20,"type":"password","identifier":"loser@example.test","secret":"retained-password-hash",
   "provider":null,"verified":true,"passwordChangedAt":-1,"providerName":null,"createdAt":-1001,"updatedAt":0},
  {"id":32,"userId":20,"type":"passkey","identifier":"passkey-identifier","secret":null,
   "provider":"stored-provider","verified":false,"passwordChangedAt":null,"providerName":"Stored provider","createdAt":0,"updatedAt":1}],
 "passkeyCredentials":[
  {"id":41,"identityId":32,"userId":20,"credentialId":"credential-original","publicKey":"public-key-original",
   "algorithm":-7,"signCount":4294967295,"transports":"[\"usb\", \"internal\"]","aaguid":null,
   "userHandle":"_wCA","label":null,"lastUsedAt":0,"createdAt":-1}],
 "secondFactors":[
  {"id":51,"userId":20,"label":"Authenticator","secret":"retained-totp-secret","lastUsedStep":123456,
   "confirmedAt":-1,"lastUsedAt":null,"createdAt":0}],
 "secondFactorBackupCodes":[
  {"id":61,"userId":20,"code":"retained-backup-code","usedAt":null,"createdAt":-1}]}
JSON;
    private const string GIVE_JSON = <<<'JSON'
{"give":"given","rows":{"identities":[31,32],"passkeyCredentials":[41],"secondFactors":[51],"secondFactorBackupCodes":[61]},"identitiesMoved":2,"passwordKept":"loser","secondFactorKept":"both"}
JSON;

    /** The fixture is boundary data; application objects contain concrete row DTOs. */
    public function testFourConcreteRowTypesPreserveAllFieldsAndNullableValues(): void
    {
        $copy = AccountMergeHandoverCopy::fromJson(self::COPY_JSON);
        self::assertContainsOnlyInstancesOf(AccountMergeIdentityRow::class, $copy->identities);
        self::assertContainsOnlyInstancesOf(AccountMergePasskeyRow::class, $copy->passkeyCredentials);
        self::assertContainsOnlyInstancesOf(AccountMergeSecondFactorRow::class, $copy->secondFactors);
        self::assertContainsOnlyInstancesOf(AccountMergeBackupCodeRow::class, $copy->secondFactorBackupCodes);
        self::assertSame(self::copyFields(), $copy->toArray());
        foreach (self::rowCases() as [$class, $fields]) {
            self::assertSame($fields, $class::fromArray($fields)->toArray());
            self::assertSame($fields, $class::fromJson(json_encode($fields, JSON_THROW_ON_ERROR))->toArray());
        }
        $fields = self::copyFields();
        $fields['identities'][0]['type'] = 'magic_link';
        $fields['identities'][0]['secret'] = null;
        $fields['secondFactors'][0]['secret'] = null;
        $fields['secondFactors'][0]['lastUsedStep'] = null;
        $fields['secondFactorBackupCodes'][0]['code'] = null;
        $fields['passkeyCredentials'][0]['aaguid'] = 'original-aaguid';
        $fields['passkeyCredentials'][0]['label'] = 'Security key';
        $fields['passkeyCredentials'][0]['transports'] = 'original, opaque transport string';
        self::assertSame($fields, AccountMergeHandoverCopy::fromArray($fields)->toArray());
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Independent row boundary fixtures */
    public static function rowCases(): iterable
    {
        $fields = self::copyFields();
        yield 'password identity' => [AccountMergeIdentityRow::class, $fields['identities'][0]];
        yield 'passkey identity' => [AccountMergeIdentityRow::class, $fields['identities'][1]];
        yield 'passkey' => [AccountMergePasskeyRow::class, $fields['passkeyCredentials'][0]];
        yield 'factor' => [AccountMergeSecondFactorRow::class, $fields['secondFactors'][0]];
        yield 'backup code' => [AccountMergeBackupCodeRow::class, $fields['secondFactorBackupCodes'][0]];
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Missing, extra and mistyped row fields */
    public static function wrongRows(): iterable
    {
        foreach (self::rowCases() as $name => [$class, $fields]) {
            foreach ($fields as $key => $value) {
                $missing = $fields;
                unset($missing[$key]);
                yield "$name missing $key" => [$class, $missing];
                $nullable = in_array($key, [
                    'secret', 'provider', 'passwordChangedAt', 'providerName', 'transports', 'aaguid', 'label',
                    'lastUsedAt', 'lastUsedStep', 'code', 'usedAt',
                ], true) && !($name === 'factor' && $key === 'label');
                $integer = is_int($value) || in_array($key, ['passwordChangedAt', 'lastUsedAt', 'lastUsedStep', 'usedAt'], true);
                $wrongValues = match (true) {
                    is_bool($value) => [0, 1, 'true', null],
                    $integer => ['1', 1.0, false, [], new stdClass()],
                    default => [1, false, [], new stdClass()],
                };
                if (!$nullable) {
                    $wrongValues[] = null;
                }
                foreach ($wrongValues as $index => $wrong) {
                    $changed = $fields;
                    $changed[$key] = $wrong;
                    yield "$name wrong $key $index" => [$class, $changed];
                }
            }
            yield "$name extra" => [$class, $fields + ['unexpected' => null]];
            foreach (['id', 'userId', 'identityId'] as $key) {
                if (!array_key_exists($key, $fields)) {
                    continue;
                }
                foreach ([0, -1] as $id) {
                    $changed = $fields;
                    $changed[$key] = $id;
                    yield "$name invalid $key $id" => [$class, $changed];
                }
            }
        }
    }

    /**
     * @param class-string $class Row reader
     * @param array<string, mixed> $fields Invalid boundary row
     */
    #[DataProvider('wrongRows')]
    public function testEveryRowFieldIsRequiredAndStrictlyTyped(string $class, array $fields): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($fields);
    }

    public function testTheRealOrmCarrierSerializesMetadataWithoutThePrivateCopy(): void
    {
        $entity = EntityAccountMergeCopy::fromRow([
            'assignment_id' => 3, 'owner_agent_id' => 'hilos_user:10', 'created_at' => -1,
            'payload_json' => self::COPY_JSON,
        ]);
        $object = ObjectAccountMergeCopy::fromEntity($entity);
        $view = new DbAccountMergeCopy($object);
        self::assertSame(['assignment_id' => 3, 'owner_agent_id' => 'hilos_user:10', 'created_at' => -1], $entity->toArray());
        $expected = ['assignmentId' => 3, 'ownerAgentId' => 'hilos_user:10', 'createdAt' => -1];
        self::assertSame($expected, $object->toArray());
        self::assertSame($expected, $view->toArray());
        self::assertSame($expected, $view->toArray(toFrontend: true));
        self::assertSame(['ownerAgentId' => 'hilos_user:10', 'createdAt' => -1], $view->toArray(withId: false));
        self::assertFalse(property_exists($entity, 'payload_json'));
        self::assertArrayNotHasKey('payload_json', EntityAccountMergeCopy::_types);
    }

    /** @return iterable<string, array{string}> Invalid copy shapes, ids and types */
    public static function wrongCopies(): iterable
    {
        foreach (['null', '[]', '1', 'true', '"copy"', '{}'] as $json) {
            yield "root $json" => [$json];
        }
        $fields = self::copyFields();
        foreach ($fields as $key => $value) {
            $missing = $fields;
            unset($missing[$key]);
            yield "missing $key" => [json_encode($missing, JSON_THROW_ON_ERROR)];
            foreach ([true, '1', 1.0, new stdClass()] as $index => $wrong) {
                $changed = $fields;
                $changed[$key] = $wrong;
                yield "wrong $key $index" => [json_encode($changed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        yield 'extra top key' => [json_encode($fields + ['secret' => 'leaked'], JSON_THROW_ON_ERROR)];
        foreach (['operationId', 'assignmentId', 'loserUserId', 'survivorUserId',
            'loserPasswordIdentityId', 'survivorPasswordIdentityId'] as $key) {
            foreach ([0, -1] as $id) {
                $changed = $fields;
                $changed[$key] = $id;
                yield "invalid $key $id" => [json_encode($changed, JSON_THROW_ON_ERROR)];
            }
        }
        $fields['effectiveWaitDays'] = -1;
        yield 'negative wait' => [json_encode($fields, JSON_THROW_ON_ERROR)];
    }

    /** @param string $json Copy that must never be normalized into valid saved data */
    #[DataProvider('wrongCopies')]
    public function testCopyRequiresExactTopKeysStrictTypesAndPositiveReferences(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeHandoverCopy::fromJson($json);
    }

    /** @return iterable<string, array{class-string}> Every new strict JSON boundary */
    public static function jsonReaders(): iterable
    {
        yield [AccountMergeHandoverCopy::class];
        yield [AccountMergeGiveResult::class];
        yield [AccountMergeIdentityRow::class];
        yield [AccountMergePasskeyRow::class];
        yield [AccountMergeSecondFactorRow::class];
        yield [AccountMergeBackupCodeRow::class];
    }

    /** @param class-string $class Boundary to exercise */
    #[DataProvider('jsonReaders')]
    public function testMalformedJsonHasItsOwnException(string $class): void
    {
        $this->expectException(InvalidJsonException::class);
        $class::fromJson('{');
    }

    /** @return iterable<string, array{class-string, string}> Valid JSON with invalid object roots */
    public static function wrongObjectRoots(): iterable
    {
        foreach (self::jsonReaders() as [$class]) {
            foreach (['[]', 'null', '1', 'false', '"row"'] as $json) {
                yield "$class $json" => [$class, $json];
            }
        }
    }

    /**
     * @param class-string $class Boundary to exercise
     * @param string $json Valid JSON outside the object contract
     */
    #[DataProvider('wrongObjectRoots')]
    public function testEveryReaderRequiresAnObjectRoot(string $class, string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromJson($json);
    }

    /** Canonical Base64Url is confined to JSON; the row retains the exact bytes. */
    public function testBinaryUserHandleRoundTripPreservesBytesAndCredentialMaterial(): void
    {
        $row = AccountMergeHandoverCopy::fromJson(self::COPY_JSON)->passkeyCredentials[0];
        self::assertSame("\xff\x00\x80", $row->userHandle);
        self::assertSame('_wCA', $row->toArray()['userHandle']);
        self::assertSame($row->userHandle, AccountMergePasskeyRow::fromJson($row->toJson())->userHandle);
        self::assertSame('credential-original', $row->credentialId);
        self::assertSame('public-key-original', $row->publicKey);
        self::assertSame(-7, $row->algorithm);
        self::assertSame(4294967295, $row->signCount);
        self::assertSame('["usb", "internal"]', $row->transports);
        $data = $row->toArray();
        foreach (['', 'AA', 'AP8', '_wCA'] as $handle) {
            $data['userHandle'] = $handle;
            self::assertSame($data, AccountMergePasskeyRow::fromArray($data)->toArray());
        }
        $data['transports'] = null;
        self::assertNull(AccountMergePasskeyRow::fromArray($data)->transports);
    }

    /** @return iterable<string, array{string}> Padded, standard-alphabet, malformed and noncanonical Base64 */
    public static function wrongHandles(): iterable
    {
        foreach (['/wCA', '+w', '_wCA=', 'AA==', 'AB', 'A', 'AA ', "AA\n", 'é', '\xff'] as $handle) {
            yield $handle => [$handle];
        }
    }

    /** @param string $handle Noncanonical encoded user handle */
    #[DataProvider('wrongHandles')]
    public function testUserHandleRejectsEveryNoncanonicalEncoding(string $handle): void
    {
        $data = self::copyFields()['passkeyCredentials'][0];
        $data['userHandle'] = $handle;
        $this->expectException(InvalidFormatException::class);
        AccountMergePasskeyRow::fromArray($data);
    }

    /** Moments are opaque signed milliseconds; the accepted TOTP step stays a step. */
    public function testSignedMillisecondsZeroNullAndTotpStepRemainDistinct(): void
    {
        $fields = self::copyFields();
        foreach ([-9223372036854775807 - 1, -1001, -1, 0, 1, 9223372036854775807] as $moment) {
            foreach (['identities', 'passkeyCredentials', 'secondFactors', 'secondFactorBackupCodes'] as $key) {
                foreach ($fields[$key] as &$row) {
                    foreach (['passwordChangedAt', 'confirmedAt', 'lastUsedAt', 'usedAt', 'createdAt', 'updatedAt'] as $field) {
                        if (array_key_exists($field, $row)) {
                            $row[$field] = $moment;
                        }
                    }
                }
                unset($row);
            }
            $copy = AccountMergeHandoverCopy::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
            self::assertSame($fields, $copy->toArray());
            self::assertSame(123456, $copy->secondFactors[0]->lastUsedStep);
        }
        $fields = self::copyFields();
        $fields['effectiveWaitDays'] = 0;
        $fields['secondFactors'][0]['lastUsedStep'] = 0;
        self::assertSame($fields, AccountMergeHandoverCopy::fromArray($fields)->toArray());
        $fields['effectiveWaitDays'] = null;
        $fields['secondFactors'][0]['lastUsedStep'] = null;
        self::assertSame($fields, AccountMergeHandoverCopy::fromArray($fields)->toArray());
    }

    /** @return iterable<string, array{string}> Cross-row violations and saved lists that require refusal */
    public static function wrongRelationships(): iterable
    {
        $fields = self::copyFields();
        foreach (['identities', 'passkeyCredentials', 'secondFactors', 'secondFactorBackupCodes'] as $key) {
            $wrong = $fields;
            $wrong[$key][0]['userId'] = 10;
            yield "$key wrong target" => [json_encode($wrong, JSON_THROW_ON_ERROR)];
            $wrong = $fields;
            $wrong[$key][] = $wrong[$key][0];
            yield "$key duplicate" => [json_encode($wrong, JSON_THROW_ON_ERROR)];
            $wrong = $fields;
            $row = $wrong[$key][0];
            $row['id'] += 100;
            $wrong[$key] = [$row, $wrong[$key][0]];
            yield "$key descending" => [json_encode($wrong, JSON_THROW_ON_ERROR)];
            foreach ([null, (object)[], (object)[0 => $fields[$key][0]], [null], [[]], [1], [false]] as $index => $list) {
                $wrong = $fields;
                $wrong[$key] = $list;
                yield "$key not a row list $index" => [json_encode($wrong, JSON_THROW_ON_ERROR)];
            }
        }
        foreach ([['loserUserId', 20], ['loserPasswordIdentityId', 999], ['survivorPasswordIdentityId', 31]] as [$key, $value]) {
            $wrong = $fields;
            $wrong[$key] = $value;
            yield "anchor $key" => [json_encode($wrong, JSON_THROW_ON_ERROR)];
        }
        $fields['passkeyCredentials'][0]['identityId'] = 999;
        yield 'passkey outside copied identities' => [json_encode($fields, JSON_THROW_ON_ERROR)];
    }

    /** @param string $json Invalid ownership, anchor or list relationship */
    #[DataProvider('wrongRelationships')]
    public function testAnchorsTargetsReferencesAndAscendingUniqueListsAreChecked(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeHandoverCopy::fromJson($json);
    }

    /** @return iterable<string, array{string, array}> Invalid internal typed lists */
    public static function wrongTypedLists(): iterable
    {
        $copy = AccountMergeHandoverCopy::fromJson(self::COPY_JSON);
        foreach (['identities', 'passkeyCredentials', 'secondFactors', 'secondFactorBackupCodes'] as $key) {
            yield "$key raw arrays" => [$key, $copy->toArray()[$key]];
            $otherRow = $key === 'identities' ? $copy->secondFactorBackupCodes[0] : $copy->identities[0];
            yield "$key wrong DTO" => [$key, [$otherRow]];
            yield "$key not a list" => [$key, [1 => $copy->{$key}[0]]];
        }
    }

    /**
     * @param string $key Typed list to replace
     * @param array $rows Wrong internal list
     */
    #[DataProvider('wrongTypedLists')]
    public function testConstructorRejectsUntypedOrMalformedLists(string $key, array $rows): void
    {
        $arguments = get_object_vars(AccountMergeHandoverCopy::fromJson(self::COPY_JSON));
        $arguments[$key] = $rows;
        $this->expectException(InvalidFormatException::class);
        new AccountMergeHandoverCopy(...$arguments);
    }

    /** Empty transfers keep every mandatory nullable key and every empty JSON list. */
    public function testEmptyCopyAndSemanticRoundTripDoNotDependOnObjectKeyOrder(): void
    {
        $fields = self::copyFields();
        $reordered = array_reverse($fields, true);
        foreach ($reordered as &$value) {
            if (is_array($value)) {
                $value = array_map(static fn(array $row): array => array_reverse($row, true), $value);
            }
        }
        unset($value);
        $copy = AccountMergeHandoverCopy::fromJson(json_encode($reordered, JSON_THROW_ON_ERROR));
        self::assertSame($fields, $copy->toArray());
        self::assertEquals(json_decode(self::COPY_JSON), json_decode($copy->toJson()));
        $empty = new AccountMergeHandoverCopy(2, 3, 10, 20, null, null, null, [], [], [], []);
        self::assertSame([
            'operationId' => 2, 'assignmentId' => 3, 'loserUserId' => 10, 'survivorUserId' => 20,
            'loserPasswordIdentityId' => null, 'survivorPasswordIdentityId' => null, 'effectiveWaitDays' => null,
            'identities' => [], 'passkeyCredentials' => [], 'secondFactors' => [], 'secondFactorBackupCodes' => [],
        ], $empty->toArray());
        self::assertSame($empty->toArray(), AccountMergeHandoverCopy::fromJson($empty->toJson())->toArray());
    }

    /** Copy secrets are explicit private JSON; the durable give fact is exactly its nonsecret manifest. */
    public function testSecretBearingCopyIsSeparateFromGiveResult(): void
    {
        $copy = AccountMergeHandoverCopy::fromJson(self::COPY_JSON);
        $fact = new AccountMergeGiveResult([31, 32], [41], [51], [61], PasswordFate::LOSER, AccountMergeGiveResult::SECOND_FACTOR_BOTH);
        foreach (['retained-password-hash', 'retained-totp-secret', 'retained-backup-code', 'loser@example.test', 'public-key-original'] as $secret) {
            self::assertStringContainsString($secret, $copy->toJson());
            self::assertStringNotContainsString($secret, $fact->toPayload()->toJson());
        }
        self::assertSame(self::GIVE_JSON, $fact->toPayload()->toJson());
        unset($copy);
        self::assertSame([31, 32], AccountMergeGiveResult::of($fact->toPayload())->identities);
    }

    /** @return iterable<string, array{string, string}> All permitted actual outcomes, including those absent from choice enums */
    public static function retentionOutcomes(): iterable
    {
        foreach (['survivor', 'loser', 'none'] as $password) {
            foreach (['both', 'loser', 'survivor', 'none'] as $factor) {
                yield "$password $factor" => [$password, $factor];
            }
        }
    }

    /**
     * @param string $password Actual password outcome
     * @param string $factor Actual protection outcome
     */
    #[DataProvider('retentionOutcomes')]
    public function testGiveResultRoundTripsThroughExistingOpaquePayload(string $password, string $factor): void
    {
        $fields = json_decode(self::GIVE_JSON, true, flags: JSON_THROW_ON_ERROR);
        $fields['passwordKept'] = $password;
        $fields['secondFactorKept'] = $factor;
        $json = json_encode(array_reverse($fields, true), JSON_THROW_ON_ERROR);
        $result = AccountMergeGiveResult::of(AccountOperationResultPayload::fromJson($json));
        self::assertSame($fields, $result->toArray());
        self::assertTrue(AccountOperationResultPayload::fromJson($json)->equals($result->toPayload()));
        self::assertSame($fields, AccountMergeGiveResult::fromArray($result->toArray())->toArray());
    }

    /** @return iterable<string, array{string}> Manifest mistakes, coercion and every possible secret insertion point */
    public static function wrongGiveResults(): iterable
    {
        $fields = json_decode(self::GIVE_JSON, true, flags: JSON_THROW_ON_ERROR);
        foreach ($fields as $key => $value) {
            $missing = $fields;
            unset($missing[$key]);
            yield "missing $key" => [json_encode($missing, JSON_THROW_ON_ERROR)];
            foreach ([null, false, 1, 1.0, 'unknown', []] as $index => $wrong) {
                $changed = $fields;
                $changed[$key] = $wrong;
                yield "wrong $key $index" => [json_encode($changed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        foreach (['copy', 'secret', 'code', 'identifier', 'assignmentId'] as $key) {
            yield "extra $key" => [json_encode($fields + [$key => 'secret'], JSON_THROW_ON_ERROR)];
        }
        foreach (['identities', 'passkeyCredentials', 'secondFactors', 'secondFactorBackupCodes'] as $key) {
            $missing = $fields;
            unset($missing['rows'][$key]);
            yield "missing list $key" => [json_encode($missing, JSON_THROW_ON_ERROR)];
            foreach ([null, (object)[], (object)[0 => 1], [0], [-1], ['1'], [1.0], [true], [null], [[]],
                [['id' => 1, 'secret' => 'leaked']], [2, 2], [2, 1]] as $index => $ids) {
                $changed = $fields;
                $changed['rows'][$key] = $ids;
                if ($key === 'identities' && is_array($ids)) {
                    $changed['identitiesMoved'] = count($ids);
                }
                yield "invalid ids $key $index" => [json_encode($changed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        $fields['rows']['payload'] = 'secret copy';
        yield 'extra manifest key' => [json_encode($fields, JSON_THROW_ON_ERROR)];
    }

    /** @param string $json Result that must not become a saved give fact */
    #[DataProvider('wrongGiveResults')]
    public function testGiveResultRejectsNonexactOrSecretBearingManifest(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeGiveResult::fromJson($json);
    }

    /** Empty give is still a complete fact with a zero identity count. */
    public function testEmptyGiveHasExactNonsecretShape(): void
    {
        $result = new AccountMergeGiveResult([], [], [], [], PasswordFate::NONE, AccountMergeGiveResult::SECOND_FACTOR_NONE);
        self::assertSame([
            'give' => 'given',
            'rows' => ['identities' => [], 'passkeyCredentials' => [], 'secondFactors' => [], 'secondFactorBackupCodes' => []],
            'identitiesMoved' => 0, 'passwordKept' => 'none', 'secondFactorKept' => 'none',
        ], $result->toArray());
        self::assertSame($result->toArray(), AccountMergeGiveResult::of($result->toPayload())->toArray());
    }

    /** @return array<string, mixed> Literal approved copy fixture at the JSON boundary */
    private static function copyFields(): array
    {
        return json_decode(self::COPY_JSON, true, flags: JSON_THROW_ON_ERROR);
    }
}
