<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\AnonymizationValidator;
use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the gate between a PII registry and an archive's schema.
 *
 * Each case is one way a restore could otherwise carry production data into a
 * non-production database while looking like it had been anonymized.
 */
final class AnonymizationValidatorTest extends TestCase
{
    public function testAFullyClassifiedArchivePasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
            'hilos_session' => AnonymizationStrategy::PURGE,
            'hilos_setting' => [],
        ]]);

        AnonymizationValidator::validate($registry, [0 => [
            $this->identitySchema(),
            new ArchiveTableSchema('hilos_session', ['id' => false], ['id' => null], ['id']),
            new ArchiveTableSchema('hilos_setting', ['key' => false], ['key' => 64], ['key']),
        ]]);

        $this->expectNotToPerformAssertions();
    }

    public function testAnUnclassifiedTableIsRefusedByName(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_audit');

        AnonymizationValidator::validate($registry, [0 => [
            $this->identitySchema(),
            new ArchiveTableSchema('hilos_audit', ['id' => false], ['id' => null], ['id']),
        ]]);
    }

    public function testADeclaredColumnMissingFromTheArchiveIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['e_mail' => AnonymizationStrategy::MASK],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_identity.e_mail');

        AnonymizationValidator::validate($registry, [0 => [$this->identitySchema()]]);
    }

    public function testNullOnANotNullColumnIsRefused(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::NULLIFY],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('NOT NULL');

        AnonymizationValidator::validate($registry, [0 => [$this->identitySchema()]]);
    }

    public function testNullOnANullableColumnPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['phone' => AnonymizationStrategy::NULLIFY],
        ]]);

        AnonymizationValidator::validate($registry, [0 => [$this->identitySchema()]]);

        $this->expectNotToPerformAssertions();
    }

    public function testAFakeStrategyOnACompositeKeyIsRefusedWithAnAlternative(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_notification_preference' => ['address' => AnonymizationStrategy::FAKE_EMAIL],
        ]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage(AnonymizationStrategy::HASH->value);

        AnonymizationValidator::validate($registry, [0 => [new ArchiveTableSchema(
            'hilos_notification_preference',
            ['user_id' => false, 'channel' => false, 'address' => false],
            ['user_id' => null, 'channel' => 32, 'address' => 255],
            ['user_id', 'channel'],
        )]]);
    }

    public function testHashOnACompositeKeyPasses(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_notification_preference' => ['address' => AnonymizationStrategy::HASH],
        ]]);

        AnonymizationValidator::validate($registry, [0 => [new ArchiveTableSchema(
            'hilos_notification_preference',
            ['user_id' => false, 'channel' => false, 'address' => false],
            ['user_id' => null, 'channel' => 32, 'address' => 255],
            ['user_id', 'channel'],
        )]]);

        $this->expectNotToPerformAssertions();
    }

    public function testARegistryRowForATableTheArchiveLacksIsSkipped(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => [
            'hilos_identity' => ['email' => AnonymizationStrategy::FAKE_EMAIL],
            'hilos_absent' => ['whatever' => AnonymizationStrategy::MASK],
        ]]);

        AnonymizationValidator::validate($registry, [0 => [$this->identitySchema()]]);

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
            AnonymizationValidator::validate($registry, [0 => [
                $this->identitySchema(),
                new ArchiveTableSchema('hilos_audit', ['id' => false], ['id' => null], ['id']),
            ]]);
            $this->fail('A registry with three problems must be refused');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString('hilos_identity.e_mail', $refusal->getMessage());
            $this->assertStringContainsString('NOT NULL', $refusal->getMessage());
            $this->assertStringContainsString('hilos_audit', $refusal->getMessage());
        }
    }

    public function testEachConnectionIsJudgedAgainstItsOwnRows(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('connection 1');

        AnonymizationValidator::validate($registry, [1 => [$this->identitySchema()]]);
    }

    /**
     * @return ArchiveTableSchema The identity table every case is judged against
     */
    private function identitySchema(): ArchiveTableSchema
    {
        return new ArchiveTableSchema(
            'hilos_identity',
            ['id' => false, 'email' => false, 'phone' => true],
            ['id' => null, 'email' => 255, 'phone' => 32],
            ['id'],
        );
    }
}
