<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\StepUpOperationKeysRule;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestDirectory;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for validation of the switched-off and switched-on operation lists (HIL-495, HIL-1275).
 */
final class StepUpOperationKeysRuleTest extends TestCase
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
        self::assertNull(StepUpOperationKeysRule::validate(''));
        self::assertNull(StepUpOperationKeysRule::validate(
            StepUpOperationKey::CHANGE_EMAIL . ',' . StepUpTestDirectory::PROJECT_OPERATION,
        ));
    }

    public function testUnknownOperationIsRefusedByName(): void
    {
        self::assertSame('Unknown operation: unknown', StepUpOperationKeysRule::validate('change_email,unknown'));
    }

    /**
     * The switched-on list names operations the same way, so the same rule stands on both (HIL-1275).
     */
    public function testAnOperationDeclaredOffIsAcceptedByName(): void
    {
        self::assertNull(StepUpOperationKeysRule::validate(
            StepUpOperationKey::BLOCK_ACCOUNT . ',' . StepUpOperationKey::REVOKE_ADMIN,
        ));
    }

    public function testNonStringValueIsRefusedByType(): void
    {
        self::assertSame('Unknown operation: int', StepUpOperationKeysRule::validate(3));
    }
}
