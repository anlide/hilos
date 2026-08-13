<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\Exception\AnonymizationConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SQL one anonymization pass runs.
 *
 * The statements are asserted as text because text is what reaches the database: the
 * two properties worth holding - a NULL stays NULL, and a hash still fits its column -
 * are visible in the expression and nowhere else until a restore has already run.
 */
final class AnonymizationSqlBuilderTest extends TestCase
{
    /** Fixed stand-in for the per-run salt, so the expected SQL can be written out. */
    private const string SALT = 'deadbeef';

    public function testHashIsSaltedAndKeepsNullAsNull(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::HASH,
            'email',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `email` IS NULL THEN NULL ELSE SHA2(CONCAT('deadbeef', `email`), 256) END",
            $expression,
        );
    }

    public function testHashIsTruncatedToAColumnItWouldNotFit(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::HASH,
            'nickname',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `nickname` IS NULL THEN NULL ELSE "
            . "LEFT(SHA2(CONCAT('deadbeef', `nickname`), 256), 32) END",
            $expression,
        );
    }

    public function testHashIsLeftWholeWhenTheColumnCarriesNoLength(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::HASH,
            'bio',
            $this->identitySchema(),
        );

        $this->assertStringNotContainsString('LEFT(', $expression);
    }

    public function testNullWritesNullOutright(): void
    {
        $this->assertSame(
            'NULL',
            $this->builder()->columnExpression(
                AnonymizationStrategy::NULLIFY,
                'phone',
                $this->identitySchema(),
            ),
        );
    }

    public function testMaskWritesTheSharedStub(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::MASK,
            'bio',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `bio` IS NULL THEN NULL ELSE '" . BackupConstants::ANONYMIZATION_MASK . "' END",
            $expression,
        );
    }

    public function testFakeEmailIsDerivedFromThePrimaryKey(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::FAKE_EMAIL,
            'email',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `email` IS NULL THEN NULL ELSE "
            . "CONCAT('user', `id`, '@example.invalid') END",
            $expression,
        );
    }

    public function testFakeNameIsDerivedFromThePrimaryKey(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::FAKE_NAME,
            'nickname',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `nickname` IS NULL THEN NULL ELSE CONCAT('User ', `id`) END",
            $expression,
        );
    }

    public function testFakePhoneIsDerivedFromThePrimaryKey(): void
    {
        $expression = $this->builder()->columnExpression(
            AnonymizationStrategy::FAKE_PHONE,
            'phone',
            $this->identitySchema(),
        );

        $this->assertSame(
            "CASE WHEN `phone` IS NULL THEN NULL ELSE CONCAT('+1555', LPAD(`id`, 7, '0')) END",
            $expression,
        );
    }

    public function testOneUpdateCarriesEveryColumnOfTheTable(): void
    {
        $statement = $this->builder()->updateStatement($this->identitySchema(), [
            'email' => AnonymizationStrategy::FAKE_EMAIL,
            'phone' => AnonymizationStrategy::NULLIFY,
        ]);

        $this->assertSame(
            'UPDATE `hilos_identity` SET '
            . "`email` = CASE WHEN `email` IS NULL THEN NULL ELSE CONCAT('user', `id`, '@example.invalid') END, "
            . '`phone` = NULL',
            $statement,
        );
    }

    public function testATableDeclaredCleanCarriesNoStatement(): void
    {
        $this->assertNull($this->builder()->updateStatement($this->identitySchema(), []));
    }

    public function testPurgeDeletesRatherThanTruncates(): void
    {
        $this->assertSame(
            'DELETE FROM `hilos_session`',
            $this->builder()->purgeStatement('hilos_session'),
        );
    }

    public function testPurgeIsNotAColumnExpression(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        $this->builder()->columnExpression(
            AnonymizationStrategy::PURGE,
            'email',
            $this->identitySchema(),
        );
    }

    public function testADerivedStrategyWithoutASinglePrimaryKeyIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        $this->builder()->columnExpression(
            AnonymizationStrategy::FAKE_NAME,
            'label',
            new ArchiveTableSchema('keyless', ['label' => false], ['label' => 32], []),
        );
    }

    public function testIdentifiersAreQuotedAgainstTheirOwnQuoteCharacter(): void
    {
        $this->assertSame(
            'DELETE FROM `odd``name`',
            $this->builder()->purgeStatement('odd`name'),
        );
    }

    /**
     * @return AnonymizationSqlBuilder Builder over a fixed salt
     */
    private function builder(): AnonymizationSqlBuilder
    {
        return new AnonymizationSqlBuilder(self::SALT);
    }

    /**
     * @return ArchiveTableSchema Identity table with one column of each shape the cases need
     */
    private function identitySchema(): ArchiveTableSchema
    {
        return new ArchiveTableSchema(
            'hilos_identity',
            ['id' => false, 'email' => false, 'phone' => true, 'nickname' => true, 'bio' => true],
            ['id' => null, 'email' => 255, 'phone' => 32, 'nickname' => 32, 'bio' => null],
            ['id'],
        );
    }
}
