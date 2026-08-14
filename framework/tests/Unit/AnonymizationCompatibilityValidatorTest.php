<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationCompatibilityValidator;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the gate between a PII registry and the schema the pass writes into.
 *
 * Every case is a column that would take the strategy on paper and refuse it in the
 * database, which is the shape this gate exists for: the archive was dumped from an older
 * schema, and the import and the forward migrations sit between it and the `UPDATE`.
 */
final class AnonymizationCompatibilityValidatorTest extends TestCase
{
    public function testACompatibleRegistryPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => [
                'email' => AnonymizationStrategy::FAKE_EMAIL,
                'phone' => AnonymizationStrategy::NULLIFY,
            ],
        ]]);

        AnonymizationCompatibilityValidator::validate($registry, 0, self::identitySchemas(), self::maxKey());

        $this->expectNotToPerformAssertions();
    }

    public function testADeclaredColumnMissingFromTheDatabaseIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['e_mail' => AnonymizationStrategy::MASK],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_identity.e_mail');

        AnonymizationCompatibilityValidator::validate($registry, 0, self::identitySchemas(), self::maxKey());
    }

    public function testNullOnANotNullColumnIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::NULLIFY],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('NOT NULL');

        AnonymizationCompatibilityValidator::validate($registry, 0, self::identitySchemas(), self::maxKey());
    }

    public function testAFakeStrategyOnACompositeKeyIsRefusedWithAnAlternative(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_notification_preference' => ['address' => AnonymizationStrategy::FAKE_EMAIL],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage(AnonymizationStrategy::HASH->value);

        AnonymizationCompatibilityValidator::validate($registry, 0, self::preferenceSchemas(), self::maxKey());
    }

    public function testHashOnACompositeKeyPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_notification_preference' => ['address' => AnonymizationStrategy::HASH],
        ]]);

        AnonymizationCompatibilityValidator::validate($registry, 0, self::preferenceSchemas(), self::maxKey());

        $this->expectNotToPerformAssertions();
    }

    public function testARegistryRowForATableTheDatabaseLacksIsSkipped(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
            'hilos_absent' => ['whatever' => AnonymizationStrategy::MASK],
        ]]);

        AnonymizationCompatibilityValidator::validate($registry, 0, self::identitySchemas(), self::maxKey());

        $this->expectNotToPerformAssertions();
    }

    public function testEveryFindingIsReportedAtOnce(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => [
                'email' => AnonymizationStrategy::NULLIFY,
                'e_mail' => AnonymizationStrategy::MASK,
            ],
        ]]);

        try {
            AnonymizationCompatibilityValidator::validate($registry, 0, self::identitySchemas(), self::maxKey());
            $this->fail('A registry with two problems must be refused over both');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString('hilos_identity.e_mail', $refusal->getMessage());
            $this->assertStringContainsString('NOT NULL', $refusal->getMessage());
        }
    }

    public function testTheConnectionUnderJudgementIsNamed(): void
    {
        $registry = PiiRegistry::fromDeclarations([1 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::NULLIFY],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('connection 1');

        AnonymizationCompatibilityValidator::validate($registry, 1, self::identitySchemas(), self::maxKey());
    }

    public function testAStrategyThatWritesTextIsRefusedOnANonTextualColumn(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => [
                'payload' => AnonymizationStrategy::MASK,
                'attempts' => AnonymizationStrategy::HASH,
            ],
        ]]);

        try {
            AnonymizationCompatibilityValidator::validate(
                $registry,
                0,
                self::probeSchemas(
                    ['payload' => true, 'attempts' => false],
                    ['payload' => 'json', 'attempts' => 'int'],
                    ['payload' => null, 'attempts' => null],
                ),
                self::maxKey(),
            );
            $this->fail('A column that holds no characters must not be told to hold a string');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString('hilos_probe.payload', $refusal->getMessage());
            $this->assertStringContainsString('json', $refusal->getMessage());
            $this->assertStringContainsString('hilos_probe.attempts', $refusal->getMessage());
            $this->assertStringContainsString('int', $refusal->getMessage());
        }
    }

    public function testAMaskWiderThanItsColumnIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['label' => AnonymizationStrategy::MASK],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_probe.label');

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(['label' => false], ['label' => 'varchar'], ['label' => 8]),
            self::maxKey(),
        );
    }

    public function testAFakeAddressFittingTheKeysTheTableHoldsPasses(): void
    {
        // `user` + four digits + `@example.invalid` is exactly the 24 the column takes. The
        // width of the key's TYPE would say ten digits here and refuse a restore that fits.
        AnonymizationCompatibilityValidator::validate(
            self::fakeEmailRegistry(),
            0,
            self::fakeEmailSchemas(),
            self::maxKey(9999),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testAFakeAddressOutgrowingItsColumnIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_probe.email');

        AnonymizationCompatibilityValidator::validate(
            self::fakeEmailRegistry(),
            0,
            self::fakeEmailSchemas(),
            self::maxKey(10000),
        );
    }

    public function testAMaskCoveringAUniqueIndexIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['email' => AnonymizationStrategy::MASK],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_probe_email');

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(
                ['email' => false],
                ['email' => 'varchar'],
                ['email' => 255],
                ['hilos_probe_email' => ['email']],
            ),
            self::maxKey(),
        );
    }

    public function testAMaskOnPartOfAUniqueIndexPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['login' => AnonymizationStrategy::MASK],
        ]]);

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(
                ['tenant' => false, 'login' => false],
                ['tenant' => 'int', 'login' => 'varchar'],
                ['tenant' => null, 'login' => 64],
                ['hilos_probe_pair' => ['tenant', 'login']],
            ),
            self::maxKey(),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testAHashCutShorterThanAUniqueIndexNeedsIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['email' => AnonymizationStrategy::HASH],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_probe.email');

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(
                ['email' => false],
                ['email' => 'varchar'],
                ['email' => 8],
                ['hilos_probe_email' => ['email']],
            ),
            self::maxKey(),
        );
    }

    public function testAHashOnAUniqueColumnWideEnoughToStayDistinctPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['email' => AnonymizationStrategy::HASH],
        ]]);

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(
                ['email' => false],
                ['email' => 'varchar'],
                ['email' => 64],
                ['hilos_probe_email' => ['email']],
            ),
            self::maxKey(),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testANarrowColumnIsNoObjectionWhenNothingIsWrittenIntoIt(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['label' => AnonymizationStrategy::NULLIFY],
        ]]);

        AnonymizationCompatibilityValidator::validate(
            $registry,
            0,
            self::probeSchemas(['label' => true], ['label' => 'varchar'], ['label' => 1]),
            self::maxKey(),
        );

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return PiiRegistry Registry whose one column takes a faked address
     */
    private static function fakeEmailRegistry(): PiiRegistry
    {
        return PiiRegistry::fromDeclarations([0 => [
            'hilos_probe' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
        ]]);
    }

    /**
     * @return array<string, LiveTableSchema> A table whose address column takes 24 characters
     */
    private static function fakeEmailSchemas(): array
    {
        return self::probeSchemas(['email' => false], ['email' => 'varchar'], ['email' => 24]);
    }

    /**
     * Stands in for the reader a restore hands the gate, which asks the live table.
     *
     * @param int $largest Largest primary key it reports for every table
     * @return callable(string, string): int Reader over a fixed answer
     */
    private static function maxKey(int $largest = 1): callable
    {
        return static fn (string $table, string $column): int => $largest;
    }

    /**
     * Builds a one-table schema around an `id` primary key.
     *
     * @param array<string, bool> $nullability Columns beside `id`, to whether they take NULL
     * @param array<string, string> $types The same columns to their types
     * @param array<string, ?int> $lengths The same columns to their character lengths
     * @param array<string, list<string>> $uniqueIndexes Unique indexes beside the primary key
     * @return array<string, LiveTableSchema> The probe table, keyed as the reader keys it
     */
    private static function probeSchemas(
        array $nullability,
        array $types,
        array $lengths,
        array $uniqueIndexes = [],
    ): array {
        return ['hilos_probe' => new LiveTableSchema(
            'hilos_probe',
            ['id' => false] + $nullability,
            ['id' => 'int'] + $types,
            ['id' => null] + $lengths,
            ['id'],
            ['PRIMARY' => ['id']] + $uniqueIndexes,
        )];
    }

    /**
     * @return array<string, LiveTableSchema> The identity table, keyed as the reader keys it
     */
    private static function identitySchemas(): array
    {
        return ['hilos_identity' => new LiveTableSchema(
            'hilos_identity',
            ['id' => false, 'email' => false, 'phone' => true],
            ['id' => 'int', 'email' => 'varchar', 'phone' => 'varchar'],
            ['id' => null, 'email' => 255, 'phone' => 32],
            ['id'],
            ['PRIMARY' => ['id']],
        )];
    }

    /**
     * @return array<string, LiveTableSchema> A table whose primary key is composite
     */
    private static function preferenceSchemas(): array
    {
        return ['hilos_notification_preference' => new LiveTableSchema(
            'hilos_notification_preference',
            ['user_id' => false, 'channel' => false, 'address' => false],
            ['user_id' => 'int', 'channel' => 'varchar', 'address' => 'varchar'],
            ['user_id' => null, 'channel' => 32, 'address' => 255],
            ['user_id', 'channel'],
            ['PRIMARY' => ['user_id', 'channel']],
        )];
    }
}
