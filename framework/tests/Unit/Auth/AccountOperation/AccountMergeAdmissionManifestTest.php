<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergeRowRevision;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact immutable revisions are never normalized or inferred from current metadata. */
final class AccountMergeAdmissionManifestTest extends TestCase
{
    private const string MANIFEST = <<<'JSON'
{"identities":[{"id":31,"revision":4},{"id":32,"revision":1}],"passkeyCredentials":[{"id":41,"revision":2}],"secondFactors":[{"id":51,"revision":3}],"secondFactorBackupCodes":[{"id":61,"revision":5}]}
JSON;

    /** Positive revisions need not increase with row ids or share one operation's allocation order. */
    public function testStampAndManifestPreserveExactOriginalCoordinatesAndEmptyLists(): void
    {
        $stamp = new AccountMergeRowRevision(31, PHP_INT_MAX);
        self::assertSame(['id' => 31, 'revision' => PHP_INT_MAX], $stamp->toArray());
        self::assertSame($stamp->toArray(), AccountMergeRowRevision::fromJson($stamp->toJson())->toArray());
        $manifest = AccountMergeAdmissionManifest::fromJson(self::MANIFEST);
        self::assertSame(self::MANIFEST, $manifest->toJson());
        self::assertSame([4, 1], array_column($manifest->identities, 'revision'));
        self::assertSame($manifest->toArray(), AccountMergeAdmissionManifest::fromArray($manifest->toArray())->toArray());
        self::assertEquals($manifest, AccountMergeAdmissionManifest::fromJson($manifest->toJson()));
        $empty = new AccountMergeAdmissionManifest([], [], [], []);
        self::assertSame('{"identities":[],"passkeyCredentials":[],"secondFactors":[],"secondFactorBackupCodes":[]}', $empty->toJson());
        self::assertEquals($empty, AccountMergeAdmissionManifest::fromJson($empty->toJson()));
    }

    /** @param string $json Missing, extra, nonpositive or mistyped stamp coordinates */
    #[DataProvider('invalidStamps')]
    public function testStampRejectsWrongKeysTypesAndNonpositiveCoordinates(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeRowRevision::fromJson($json);
    }

    /** @return iterable<string, array{string}> Exact two-field stamps, without coercion */
    public static function invalidStamps(): iterable
    {
        foreach (['[]', 'null', '1', '{"id":1}', '{"revision":1}', '{"id":1,"revision":1,"secret":"x"}'] as $json) {
            yield $json => [$json];
        }
        foreach (['id', 'revision'] as $key) {
            foreach ([null, false, 0, -1, '1', 1.0, [], (object)[]] as $index => $value) {
                $fields = ['id' => 1, 'revision' => 1];
                $fields[$key] = $value;
                yield $key . $index => [json_encode($fields, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
    }

    /** @param string $json Missing or extra manifest fields, wrong list containers and invalid ordering */
    #[DataProvider('invalidManifests')]
    public function testManifestRejectsMissingExtraUntypedDuplicateAndUnorderedLists(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeAdmissionManifest::fromJson($json);
    }

    /** @return iterable<string, array{string}> All four lists obey the same strict saved format */
    public static function invalidManifests(): iterable
    {
        foreach (['[]', 'null', '{}', '{"identities":[]}'] as $json) {
            yield $json => [$json];
        }
        $fields = json_decode(self::MANIFEST, true, flags: JSON_THROW_ON_ERROR);
        yield 'extra operation' => [json_encode($fields + ['operationId' => 1], JSON_THROW_ON_ERROR)];
        foreach (array_keys($fields) as $key) {
            $missing = $fields;
            unset($missing[$key]);
            yield 'missing ' . $key => [json_encode($missing, JSON_THROW_ON_ERROR)];
            foreach ([null, (object)[], (object)[0 => ['id' => 1, 'revision' => 1]], [1], [[]],
                [['id' => 1, 'revision' => 1], ['id' => 1, 'revision' => 2]],
                [['id' => 2, 'revision' => 1], ['id' => 1, 'revision' => 2]],
                [['id' => 1, 'revision' => 0]], [['id' => '1', 'revision' => 1]],
                [['id' => 1, 'revision' => 1, 'code' => 'secret']]] as $index => $rows) {
                $changed = $fields;
                $changed[$key] = $rows;
                yield $key . $index => [json_encode($changed, JSON_THROW_ON_ERROR)];
            }
        }
    }

    /** @param array $rows Lists at the typed constructor boundary */
    #[DataProvider('untypedLists')]
    public function testTypedConstructorNeverNormalizesMalformedLists(array $rows): void
    {
        $this->expectException(InvalidFormatException::class);
        new AccountMergeAdmissionManifest($rows, [], [], []);
    }

    /** @return iterable<string, array{array}> Constructor failures cannot be hidden by JSON parsing */
    public static function untypedLists(): iterable
    {
        yield 'raw row' => [[['id' => 1, 'revision' => 1]]];
        yield 'nonlist' => [[1 => new AccountMergeRowRevision(1, 1)]];
        yield 'duplicate' => [[new AccountMergeRowRevision(1, 1), new AccountMergeRowRevision(1, 2)]];
        yield 'unordered' => [[new AccountMergeRowRevision(2, 1), new AccountMergeRowRevision(1, 1)]];
    }

    /** @param string $class Both strict readers distinguish malformed JSON from a malformed contract */
    #[DataProvider('readers')]
    public function testMalformedJsonHasItsOwnException(string $class): void
    {
        $this->expectException(InvalidJsonException::class);
        $class::fromJson('{');
    }

    /** @return iterable<string, array{class-string}> Both immutable boundary readers */
    public static function readers(): iterable
    {
        yield 'stamp' => [AccountMergeRowRevision::class];
        yield 'manifest' => [AccountMergeAdmissionManifest::class];
    }
}
