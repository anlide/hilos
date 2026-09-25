<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\StepUpDisabledRule;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestDirectory;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for validation of the disabled operation list (HIL-495).
 */
final class StepUpDisabledRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StepUpTestHilos::mount(null);
    }

    protected function tearDown(): void
    {
        StepUpTestHilos::unmount();

        parent::tearDown();
    }

    public function testEmptyAndDeclaredListsAreAccepted(): void
    {
        self::assertNull(StepUpDisabledRule::validate(''));
        self::assertNull(StepUpDisabledRule::validate(
            StepUpOperationKey::CHANGE_EMAIL . ',' . StepUpTestDirectory::PROJECT_OPERATION,
        ));
    }

    public function testUnknownOperationIsRefusedByName(): void
    {
        self::assertSame('Unknown operation: unknown', StepUpDisabledRule::validate('change_email,unknown'));
    }

    public function testNonStringValueIsRefusedByType(): void
    {
        self::assertSame('Unknown operation: int', StepUpDisabledRule::validate(3));
    }
}
