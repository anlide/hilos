<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestDirectory;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the disabled step-up operation setting (HIL-495).
 */
final class StepUpSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        StepUpTestHilos::unmount();

        parent::tearDown();
    }

    public function testParseNormalizesWhitespaceDuplicatesAndBlanks(): void
    {
        self::assertSame(
            [StepUpOperationKey::CHANGE_EMAIL, StepUpTestDirectory::PROJECT_OPERATION],
            StepUpSettings::parse(' change_email, ,project_operation,change_email '),
        );
    }

    public function testFormatKeepsGivenDirectoryOrder(): void
    {
        self::assertSame(
            'change_password,project_operation',
            StepUpSettings::format([StepUpOperationKey::CHANGE_PASSWORD, StepUpTestDirectory::PROJECT_OPERATION]),
        );
    }

    public function testEveryDeclaredOperationIsEnabledByDefault(): void
    {
        StepUpTestHilos::mount(null);

        self::assertSame([], StepUpSettings::disabledKeys());
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::CHANGE_PASSWORD));
        self::assertTrue(StepUpSettings::isEnabled(StepUpTestDirectory::PROJECT_OPERATION));
    }

    public function testStoredOperationsAreDisabled(): void
    {
        StepUpTestHilos::mount('change_email,project_operation');

        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::CHANGE_EMAIL));
        self::assertFalse(StepUpSettings::isEnabled(StepUpTestDirectory::PROJECT_OPERATION));
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::DELETE_ACCOUNT));
    }

    public function testAnUnknownOperationIsNeverEnabled(): void
    {
        StepUpTestHilos::mount(null);

        self::assertFalse(StepUpSettings::isEnabled('unknown'));
    }
}
