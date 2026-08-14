<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the gate between a PII registry and the tables an archive carries.
 *
 * One question - is every table of the archive classified - and each case is one way a
 * restore could otherwise carry production data into a non-production database while
 * looking like it had been anonymized. Whether a classified column can carry its strategy
 * is asked later, against the live schema
 * ({@see AnonymizationCompatibilityValidatorTest}).
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

        AnonymizationCoverageValidator::validate(
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

        AnonymizationCoverageValidator::validate($registry, [0 => ['hilos_identity', 'hilos_audit']]);
    }

    public function testEveryUnclassifiedTableIsNamedAtOnce(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        try {
            AnonymizationCoverageValidator::validate(
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

        AnonymizationCoverageValidator::validate($registry, [0 => ['hilos_identity']]);

        $this->expectNotToPerformAssertions();
    }

    public function testEachConnectionIsJudgedAgainstItsOwnTables(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['hilos_identity' => []]]);

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('connection 1');

        AnonymizationCoverageValidator::validate($registry, [1 => ['hilos_identity']]);
    }
}
