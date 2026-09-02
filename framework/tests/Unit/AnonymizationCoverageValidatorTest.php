<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\UnclassifiedLiveSchemaException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the gate that asks whether a PII registry covers everything.
 *
 * Two questions asked of the same registry, and the cases here are split by which one:
 * the archive's tables, and the live schema's tables and columns. Each case is one way a
 * restore could otherwise carry production data into a non-production database while
 * looking like it had been anonymized, or - on the live-schema side - one way a column
 * added by a migration could go out of a node undeclared and unnoticed. Whether a
 * classified column can carry its strategy is a third question, asked against the live
 * schema by {@see AnonymizationCompatibilityValidatorTest}.
 */
final class AnonymizationCoverageValidatorTest extends TestCase
{
    public function testAFullyClassifiedArchivePasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
            'hilos_session' => AnonymizationStrategy::PURGE,
            'hilos_setting' => [],
        ]]);

        AnonymizationCoverageValidator::validateArchiveTables(
            $registry,
            [0 => ['hilos_identity', 'hilos_session', 'hilos_setting']],
        );

        $this->expectNotToPerformAssertions();
    }

    public function testAnUnclassifiedTableIsRefusedByName(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_audit');

        AnonymizationCoverageValidator::validateArchiveTables($registry, [0 => ['hilos_identity', 'hilos_audit']]);
    }

    public function testEveryUnclassifiedTableIsNamedAtOnce(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        try {
            AnonymizationCoverageValidator::validateArchiveTables(
                $registry,
                [0 => ['hilos_identity', 'hilos_audit', 'hilos_outbox']],
            );
            $this->fail('An archive with two unclassified tables must be refused');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString('hilos_audit', $refusal->getMessage());
            $this->assertStringContainsString('hilos_outbox', $refusal->getMessage());
        }
    }

    public function testARegistryRowForATableTheArchiveLacksIsSkipped(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
            'hilos_absent' => ['whatever' => AnonymizationStrategy::MASK],
        ]]);

        AnonymizationCoverageValidator::validateArchiveTables($registry, [0 => ['hilos_identity']]);

        $this->expectNotToPerformAssertions();
    }

    public function testEachConnectionIsJudgedAgainstItsOwnTables(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('connection 1');

        AnonymizationCoverageValidator::validateArchiveTables($registry, [1 => ['hilos_identity']]);
    }

    public function testAFullyClassifiedLiveSchemaPasses(): void
    {
        $registry = new PiiRegistry(
            [0 => [
                'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
                'hilos_setting' => [],
            ]],
            [0 => [
                'hilos_identity' => ['id'],
                'hilos_setting' => ['id', 'name'],
            ]],
        );

        AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => [
            'hilos_identity' => self::schema('hilos_identity', ['id', 'email']),
            'hilos_setting' => self::schema('hilos_setting', ['id', 'name']),
        ]]);

        $this->expectNotToPerformAssertions();
    }

    public function testAColumnNamedByNeitherHalfIsRefusedByItsOwnName(): void
    {
        $registry = new PiiRegistry(
            [0 => ['hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL]]],
            [0 => ['hilos_identity' => ['id']]],
        );

        $this->expectException(UnclassifiedLiveSchemaException::class);
        $this->expectExceptionMessage('columns carry no PII verdict: hilos_identity.timezone');

        AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => [
            'hilos_identity' => self::schema('hilos_identity', ['id', 'email', 'timezone']),
        ]]);
    }

    public function testAPurgedTableIsNotAskedAboutItsColumns(): void
    {
        $registry = new PiiRegistry([0 => ['hilos_session' => AnonymizationStrategy::PURGE]]);

        AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => [
            'hilos_session' => self::schema('hilos_session', ['id', 'token', 'last_seen_at']),
        ]]);

        $this->expectNotToPerformAssertions();
    }

    public function testAnUnclassifiedTableIsNamedWholeAndItsColumnsAreNot(): void
    {
        $registry = new PiiRegistry([0 => ['hilos_identity' => []]], [0 => ['hilos_identity' => ['id']]]);

        try {
            AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => [
                'hilos_identity' => self::schema('hilos_identity', ['id']),
                'chat_reaction' => self::schema('chat_reaction', ['id', 'emoji', 'author_id']),
            ]]);
            $this->fail('A live schema carrying an unclassified table must be refused');
        } catch (UnclassifiedLiveSchemaException $refusal) {
            $this->assertStringContainsString('tables carry no PII verdict: chat_reaction', $refusal->getMessage());
            $this->assertStringNotContainsString(
                'emoji',
                $refusal->getMessage(),
                'One unclassified table must cost one line, not one line per column of it',
            );
        }
    }

    public function testFindingsOfEveryConnectionArriveInOneRefusal(): void
    {
        $registry = new PiiRegistry(
            [
                0 => ['hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL]],
                1 => [],
            ],
            [0 => ['hilos_identity' => ['id']]],
        );

        try {
            AnonymizationCoverageValidator::validateLiveSchema($registry, [
                0 => ['hilos_identity' => self::schema('hilos_identity', ['id', 'email', 'timezone'])],
                1 => ['chat_pin' => self::schema('chat_pin', ['id'])],
            ]);
            $this->fail('Findings on two connections must be refused');
        } catch (UnclassifiedLiveSchemaException $refusal) {
            $this->assertStringContainsString(
                'connection 0: columns carry no PII verdict: hilos_identity.timezone',
                $refusal->getMessage(),
            );
            $this->assertStringContainsString(
                'connection 1: tables carry no PII verdict: chat_pin',
                $refusal->getMessage(),
            );
        }
    }

    /**
     * Builds a live table of the named columns, with only what coverage asks of it.
     *
     * Coverage judges names alone, so the types, widths and keys a compatibility question
     * would need are given the one shape that cannot be mistaken for a claim about them.
     *
     * @param string $table Table name
     * @param list<string> $columns Column names, in the ordinal order the reader returns
     * @return LiveTableSchema Live table over those columns
     */
    private static function schema(string $table, array $columns): LiveTableSchema
    {
        return new LiveTableSchema(
            $table,
            array_fill_keys($columns, false),
            array_fill_keys($columns, 'varchar'),
            array_fill_keys($columns, 255),
            ['id'],
            ['PRIMARY' => ['id']],
            [],
        );
    }
}
