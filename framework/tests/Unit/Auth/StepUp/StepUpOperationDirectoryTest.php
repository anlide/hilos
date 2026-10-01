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
            StepUpOperationKey::EXPORT_DATA,
            StepUpOperationKey::ADD_AUTHENTICATOR_APP,
            StepUpOperationKey::ADD_SIGN_IN_METHOD,
            StepUpOperationKey::MERGE_ACCOUNTS,
            StepUpOperationKey::GRANT_ADMIN,
            StepUpOperationKey::REVOKE_ADMIN,
            StepUpOperationKey::BLOCK_ACCOUNT,
            StepUpOperationKey::DELETE_OTHER_ACCOUNT,
            StepUpOperationKey::IMPERSONATE,
            StepUpTestDirectory::PROJECT_OPERATION,
        ], StepUpTestDirectory::keys());
    }

    /**
     * The administrator's operations on another person's account: three declared on, the three an
     * administrator undoes with one action declared off (HIL-1275) - the takeover among them, whose
     * "Stop" returns everything (HIL-1170).
     */
    public function testAdministratorOperationsCarryTheirCopyAndTheirDefaults(): void
    {
        $expected = [
            StepUpOperationKey::MERGE_ACCOUNTS => ['Merge accounts', 'merge an account into this one', true],
            StepUpOperationKey::GRANT_ADMIN => ['Grant admin rights', 'grant admin rights', true],
            StepUpOperationKey::REVOKE_ADMIN => ['Remove admin rights', 'remove admin rights', false],
            StepUpOperationKey::BLOCK_ACCOUNT => ['Block an account', 'block this account', false],
            StepUpOperationKey::DELETE_OTHER_ACCOUNT => ["Delete another person's account", 'delete this account', true],
            StepUpOperationKey::IMPERSONATE => ['Impersonate a person', 'impersonate this person', false],
        ];
        foreach ($expected as $key => [$label, $purpose, $enabledByDefault]) {
            $operation = StepUpTestDirectory::get($key);

            self::assertSame($label, $operation->label, $key);
            self::assertSame($purpose, $operation->purpose, $key);
            self::assertSame($enabledByDefault, $operation->enabledByDefault, $key);
            self::assertFalse($operation->opensWithAddressCode, $key);
            self::assertFalse($operation->opensOnBlockedCard, $key);
            self::assertFalse($operation->passesWithNothingToConfirm, $key);
            self::assertFalse($operation->opensWithSecondFactorProof, $key);
            self::assertTrue(StepUpTestDirectory::isFramework($key), $key);
        }
    }

    public function testAnOperationIsDeclaredOnUnlessItSaysOtherwise(): void
    {
        self::assertTrue(StepUpTestDirectory::get(StepUpOperationKey::CHANGE_PASSWORD)->enabledByDefault);
        self::assertTrue(StepUpTestDirectory::get(StepUpTestDirectory::PROJECT_OPERATION)->enabledByDefault);
    }

    public function testDescriptorCarriesAdministrationAndConfirmationCopy(): void
    {
        $operation = StepUpTestDirectory::get(StepUpOperationKey::CHANGE_EMAIL);

        self::assertSame('Change email', $operation->label);
        self::assertSame('change your email', $operation->purpose);
        self::assertTrue($operation->opensWithAddressCode);
        self::assertFalse(StepUpTestDirectory::get(StepUpTestDirectory::PROJECT_OPERATION)->opensWithAddressCode);
        self::assertTrue(StepUpTestDirectory::get(StepUpOperationKey::EXPORT_DATA)->opensOnBlockedCard);
        self::assertTrue(StepUpTestDirectory::get(StepUpOperationKey::EXPORT_DATA)->passesWithNothingToConfirm);
        self::assertFalse($operation->opensOnBlockedCard);
        self::assertFalse($operation->passesWithNothingToConfirm);
        self::assertFalse($operation->opensWithSecondFactorProof);
    }

    /**
     * The two adding operations pass an account with nothing to confirm with, and only the app
     * one takes a code from a connected app for its confirmation (HIL-1138).
     */
    public function testAddingOperationsCarryTheirCopyAndTheirPasses(): void
    {
        $app = StepUpTestDirectory::get(StepUpOperationKey::ADD_AUTHENTICATOR_APP);
        $wayIn = StepUpTestDirectory::get(StepUpOperationKey::ADD_SIGN_IN_METHOD);

        self::assertSame('Add an authenticator app', $app->label);
        self::assertSame('add an authenticator app', $app->purpose);
        self::assertSame('Add a way to sign in', $wayIn->label);
        self::assertSame('add a way to sign in', $wayIn->purpose);
        self::assertFalse($app->opensWithAddressCode);
        self::assertFalse($wayIn->opensWithAddressCode);
        self::assertTrue($app->passesWithNothingToConfirm);
        self::assertTrue($wayIn->passesWithNothingToConfirm);
        self::assertTrue($app->opensWithSecondFactorProof);
        self::assertFalse($wayIn->opensWithSecondFactorProof);
        self::assertFalse($app->opensOnBlockedCard);
        self::assertFalse($wayIn->opensOnBlockedCard);
        self::assertTrue(StepUpTestDirectory::isFramework(StepUpOperationKey::ADD_AUTHENTICATOR_APP));
        self::assertTrue(StepUpTestDirectory::isFramework(StepUpOperationKey::ADD_SIGN_IN_METHOD));
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
