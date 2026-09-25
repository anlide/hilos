<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework/project protected-operation directory (HIL-495).
 */
final class StepUpOperationDirectoryTest extends TestCase
{
    public function testFrameworkAndProjectOperationsKeepDeclarationOrder(): void
    {
        self::assertSame([
            StepUpOperationKey::CHANGE_PASSWORD,
            StepUpOperationKey::CHANGE_EMAIL,
            StepUpOperationKey::DELETE_ACCOUNT,
            StepUpTestDirectory::PROJECT_OPERATION,
        ], StepUpTestDirectory::keys());
    }

    public function testDescriptorCarriesAdministrationAndConfirmationCopy(): void
    {
        $operation = StepUpTestDirectory::get(StepUpOperationKey::CHANGE_EMAIL);

        self::assertSame('Change email', $operation->label);
        self::assertSame('change your email', $operation->purpose);
        self::assertTrue($operation->opensWithAddressCode);
        self::assertFalse(StepUpTestDirectory::get(StepUpTestDirectory::PROJECT_OPERATION)->opensWithAddressCode);
    }

    public function testFrameworkOwnershipDoesNotIncludeProjectOperations(): void
    {
        self::assertTrue(StepUpTestDirectory::isFramework(StepUpOperationKey::DELETE_ACCOUNT));
        self::assertFalse(StepUpTestDirectory::isFramework(StepUpTestDirectory::PROJECT_OPERATION));
    }

    public function testUnknownOperationIsAProjectError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StepUpTestDirectory::get('unknown');
    }

    public function testOperationKeyMustUseStorageAndWireGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StepUpOperation('Change email', 'Change email', 'change your email', true);
    }
}
