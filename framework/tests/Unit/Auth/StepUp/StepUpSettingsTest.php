<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestDirectory;
use Hilos\Tests\Unit\Auth\StepUp\Fixtures\StepUpTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two step-up operation lists (HIL-495, HIL-1275).
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

    /**
     * An operation declared off stays off with no stored row, and the switched-on list turns it on (HIL-1275).
     */
    public function testAnOperationDeclaredOffAsksOnlyOnceSwitchedOn(): void
    {
        StepUpTestHilos::mount(null);

        self::assertSame([], StepUpSettings::enabledKeys());
        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::REVOKE_ADMIN));
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::MERGE_ACCOUNTS));

        StepUpTestHilos::mount(null, StepUpOperationKey::BLOCK_ACCOUNT);

        self::assertSame([StepUpOperationKey::BLOCK_ACCOUNT], StepUpSettings::enabledKeys());
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::REVOKE_ADMIN));
    }

    /**
     * Each list speaks for its own side only: an operation declared off named in the switched-off
     * list, or one declared on named in the switched-on list, changes nothing.
     */
    public function testAListNamingTheOtherSideChangesNothing(): void
    {
        StepUpTestHilos::mount(StepUpOperationKey::BLOCK_ACCOUNT, StepUpOperationKey::GRANT_ADMIN);

        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::GRANT_ADMIN));
    }

    /**
     * The reason for two lists: a switched-off list stored before an operation declared off
     * existed does not name it, and the operation still stands where it was declared.
     */
    public function testAStoredSwitchedOffListDoesNotTurnOnAnOperationDeclaredOff(): void
    {
        StepUpTestHilos::mount(StepUpOperationKey::CHANGE_EMAIL);

        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::CHANGE_EMAIL));
        self::assertFalse(StepUpSettings::isEnabled(StepUpOperationKey::BLOCK_ACCOUNT));
        self::assertTrue(StepUpSettings::isEnabled(StepUpOperationKey::DELETE_OTHER_ACCOUNT));
    }

    public function testASwitchWritesTheListOfTheDeclaredPosition(): void
    {
        StepUpTestHilos::mount(null);

        self::assertSame(StepUpSettings::DISABLED_KEY, StepUpSettings::listKeyFor(StepUpOperationKey::MERGE_ACCOUNTS));
        self::assertSame(StepUpSettings::DISABLED_KEY, StepUpSettings::listKeyFor(StepUpTestDirectory::PROJECT_OPERATION));
        self::assertSame(StepUpSettings::ENABLED_KEY, StepUpSettings::listKeyFor(StepUpOperationKey::BLOCK_ACCOUNT));
        self::assertSame(StepUpSettings::ENABLED_KEY, StepUpSettings::listKeyFor(StepUpOperationKey::REVOKE_ADMIN));
    }

    public function testAnUnknownOperationHasNoList(): void
    {
        StepUpTestHilos::mount(null);

        $this->expectException(InvalidArgumentException::class);

        StepUpSettings::listKeyFor('unknown');
    }
}
