<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for drawing, showing and reading back backup codes (HIL-494).
 */
final class BackupCodeGeneratorTest extends TestCase
{
    /**
     * A set holds the asked number of distinct ten-character codes from the unambiguous alphabet.
     */
    public function testASetIsDistinctAndUnambiguous(): void
    {
        $codes = BackupCodeGenerator::generate(20);

        self::assertCount(20, $codes);
        self::assertCount(20, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{10}$/', $code);
        }
    }

    /**
     * A code is shown as two groups of five.
     */
    public function testACodeIsShownInTwoGroups(): void
    {
        self::assertSame('abcde-fghjk', BackupCodeGenerator::display('abcdefghjk'));
    }

    /**
     * Whatever case, spaces and hyphens a person typed, the stored form comes back.
     */
    public function testATypedCodeIsNormalized(): void
    {
        self::assertSame('abcdefghjk', BackupCodeGenerator::normalize(' ABCDE-fghjk '));
        self::assertSame('abcdefghjk', BackupCodeGenerator::normalize('abc de fgh-jk'));
    }

    /**
     * Showing and reading back are inverse.
     */
    public function testShownCodeReadsBack(): void
    {
        foreach (BackupCodeGenerator::generate(5) as $code) {
            self::assertSame($code, BackupCodeGenerator::normalize(BackupCodeGenerator::display($code)));
        }
    }
}
