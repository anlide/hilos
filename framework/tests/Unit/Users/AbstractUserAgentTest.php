<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\UserMerge;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\Agent\AbstractUserAgentDaemon;
use Hilos\Users\DTO\UserAddressVerifySignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserEmailChangeSignalData;
use Hilos\Users\DTO\UserIdentityUnlinkSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordRehashSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollConfirmSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Users\DTO\UserSecondFactorUnlockSignalData;
use Hilos\Users\DTO\UserSecondFactorWaitWriteSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The person agent claims its own row and sets without taking the libraries' creation rights. */
final class AbstractUserAgentTest extends TestCase
{
    protected function tearDown(): void
    {
        $agentId = HilosAgentType::HILOS_USER . ':42';
        TruthSourceRegistry::unregisterAgent($agentId);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        SourceInterestRegistry::readsWhatItMounts();
        ExecutionContext::setCurrentAgentId(null);

        parent::tearDown();
    }

    public function testPositiveIndexIsUsedForEveryClaim(): void
    {
        $agent = new TestUserAgent('42');
        $daemon = new TestUserAgentDaemon('42');

        self::assertSame(HilosAgentType::HILOS_USER, $agent->getType());
        self::assertSame('42', $agent->getIndex());
        self::assertSame($agent->getId(), $daemon->getId());
        self::assertSame(['42'], $agent->ownedDbRowKeys(HilosDbContext::users));
        foreach (array_keys(TestUserAgent::OWNS_DB_SET) as $collection) {
            self::assertSame('42', $agent->ownedDbSetKey($collection));
        }
    }

    public function testMissingIndexIsRefused(): void
    {
        $this->expectException(AgentIndexRequiredException::class);

        new TestUserAgent('');
    }

    #[DataProvider('invalidIndices')]
    public function testInvalidIndexIsRefused(string $index): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new TestUserAgent($index);
    }

    #[DataProvider('invalidIndices')]
    public function testInvalidDaemonIndexIsRefused(string $index): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new TestUserAgentDaemon($index);
    }

    /**
     * @return iterable<string, array{string}> Invalid agent indices
     */
    public static function invalidIndices(): iterable
    {
        yield 'zero' => ['0'];
        yield 'padded' => ['00042'];
        yield 'negative' => ['-1'];
        yield 'text' => ['person'];
        yield 'decimal' => ['1.5'];
    }

    public function testDeclaredClaimsHaveExactlyTheAgreedWidthsAndOperations(): void
    {
        self::assertSame([HilosDbContext::users], array_keys(TestUserAgent::OWNS_DB_ROWS));
        self::assertSame([TruthSourceOperation::Update], TestUserAgent::OWNS_DB_ROWS[HilosDbContext::users]);
        self::assertSame([
            HilosDbContext::identities,
            HilosDbContext::passkeyCredentials,
            HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes,
            HilosDbContext::secondFactorResets,
            HilosDbContext::secondFactorSettings,
            HilosDbContext::secondFactorTrusts,
            HilosDbContext::stepUps,
            HilosDbContext::accountDeletions,
            HilosDbContext::userPhotos,
            HilosDbContext::notifications,
            HilosDbContext::notificationPreferences,
            HilosDbContext::pushSubscriptions,
            HilosDbContext::userRenames,
        ], array_keys(TestUserAgent::OWNS_DB_SET));
        foreach (self::borrowedSets() as $collection) {
            self::assertSame([TruthSourceOperation::Update, TruthSourceOperation::Remove], TestUserAgent::OWNS_DB_SET[$collection]);
        }
        self::assertSame([TruthSourceOperation::Add], TestUserAgent::OWNS_DB_SET[HilosDbContext::userRenames]);
        self::assertSame(
            [TruthSourceOperation::Add, TruthSourceOperation::Update],
            TestUserAgent::OWNS_DB_SET[HilosDbContext::secondFactorSettings],
        );
        self::assertSame([], TestUserAgent::OWNS_DB);
        self::assertSame([], TestUserAgent::READS_DB);
        self::assertSame([], TestUserAgent::OWNS_RT);
        self::assertSame([], TestUserAgent::OWNS_RT_ROWS);
        self::assertSame([], TestUserAgent::OWNS_RT_SET);
    }

    public function testRegistryGrantsThePersonButRejectsAnotherSetAndCreation(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new TestUserAgent('42');
        OwnershipDeclaration::claimDbRows($agent);
        OwnershipDeclaration::claimDbSet($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        TruthSourceRegistry::checkCanWriteItem(HilosDbContext::users, '42', static fn (): array => [], TruthSourceOperation::Update);
        TruthSourceRegistry::checkCanCreate(HilosDbContext::userRenames, static fn (): array => ['42']);
        TruthSourceRegistry::checkCanCreate(HilosDbContext::secondFactorSettings, static fn (): array => ['42']);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::secondFactorSettings,
            '42',
            static fn (): array => ['42'],
            TruthSourceOperation::Update,
        );
        foreach (self::borrowedSets() as $collection) {
            TruthSourceRegistry::checkCanWriteItem($collection, '1', static fn (): array => ['42'], TruthSourceOperation::Update);
            TruthSourceRegistry::checkCanWriteItem($collection, '1', static fn (): array => ['42'], TruthSourceOperation::Remove);
            self::assertFalse(TruthSourceRegistry::hasCreateSource($collection));
        }

        $this->expectException(WriteNotAllowedException::class);
        TruthSourceRegistry::checkCanWriteItem(
            HilosDbContext::notifications,
            '2',
            static fn (): array => ['43'],
            TruthSourceOperation::Update,
        );
    }

    public function testRenameJournalRowsOfAnotherPersonAreNotCreated(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new TestUserAgent('42');
        OwnershipDeclaration::claimDbSet($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(HilosDbContext::userRenames, static fn (): array => ['43']);
    }

    public function testSecondFactorSettingsOfAnotherPersonAreNotCreated(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new TestUserAgent('42');
        OwnershipDeclaration::claimDbSet($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        $this->expectException(CreateNotAllowedException::class);
        TruthSourceRegistry::checkCanCreate(HilosDbContext::secondFactorSettings, static fn (): array => ['43']);
    }

    public function testEveryEditFrameIsAddressedByThePersonId(): void
    {
        self::assertSame([
            HilosSignalConstants::HILOS_USER_RENAME => UserRenameSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE => UserAdminWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => UserAdminCommandSignalData::class,
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE => UserBlockWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => UserPasswordRehashSignalData::class,
            HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => UserAddressVerifySignalData::class,
            HilosSignalConstants::HILOS_USER_PASSKEY_USE => UserPasskeyUseSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET => UserPasswordResetSignalData::class,
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => UserPasswordChangeSignalData::class,
            HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => UserEmailChangeSignalData::class,
            HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => UserIdentityUnlinkSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => UserSecondFactorProveSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => UserSecondFactorEnrollConfirmSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => UserSecondFactorRemoveSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => UserSecondFactorResetCancelSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => UserSecondFactorWaitWriteSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => UserSecondFactorResetDueSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => UserSecondFactorResetRemindSignalData::class,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => UserSecondFactorUnlockSignalData::class,
        ], array_map(
            static fn (array $config): string => $config[AgentSignalConfigKey::DTO],
            TestUserAgent::AGENT_SIGNALS,
        ));
        foreach (TestUserAgent::AGENT_SIGNALS as $config) {
            self::assertSame('userId', $config[AgentSignalConfigKey::INDEX_FIELD]);
        }
    }

    public function testDeletingThePersonsOwnRowAsksTheAgentToStop(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncDeleted(
            new DbSyncDeletedSignalData(HilosDbContext::users, '42'),
            'db',
            SignalConstants::DB_SYNC_DELETED,
        );

        self::assertTrue($agent->shouldStop());
    }

    public function testAMergeRowForThePersonAsksTheAgentToStop(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncCreated(
            new DbSyncCreatedSignalData(HilosDbContext::userMerges, '42', [
                UserMerge::user_id => 42,
                UserMerge::survivor_user_id => 7,
            ]),
            'db',
            SignalConstants::DB_SYNC_CREATED,
        );

        self::assertTrue($agent->shouldStop());
    }

    public function testAnotherPersonsDeletionDoesNotStopTheAgent(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncDeleted(
            new DbSyncDeletedSignalData(HilosDbContext::users, '43'),
            'db',
            SignalConstants::DB_SYNC_DELETED,
        );

        self::assertFalse($agent->shouldStop());
    }

    public function testTheSurvivorOfAMergeDoesNotStop(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncCreated(
            new DbSyncCreatedSignalData(HilosDbContext::userMerges, '43', [
                UserMerge::user_id => 43,
                UserMerge::survivor_user_id => 42,
            ]),
            'db',
            SignalConstants::DB_SYNC_CREATED,
        );

        self::assertFalse($agent->shouldStop());
    }

    public function testUpdatingThePersonsRowDoesNotStopTheAgent(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncUpdated(
            new DbSyncUpdatedSignalData(HilosDbContext::users, '42', []),
            'db',
            SignalConstants::DB_SYNC_UPDATED,
        );

        self::assertFalse($agent->shouldStop());
    }

    public function testDeletingThePersonsMergeRowDoesNotStopTheAgent(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncDeleted(
            new DbSyncDeletedSignalData(HilosDbContext::userMerges, '42'),
            'db',
            SignalConstants::DB_SYNC_DELETED,
        );

        self::assertFalse($agent->shouldStop());
    }

    public function testDeletingAChildRowDoesNotStopTheAgent(): void
    {
        $agent = new TestUserAgent('42');

        $agent->onSignalDbSyncDeleted(
            new DbSyncDeletedSignalData(HilosDbContext::identities, '42'),
            'db',
            SignalConstants::DB_SYNC_DELETED,
        );

        self::assertFalse($agent->shouldStop());
    }

    /**
     * @return list<string> Child sets the agent borrows to edit and remove, never to add
     */
    private static function borrowedSets(): array
    {
        return array_values(array_diff(
            array_keys(TestUserAgent::OWNS_DB_SET),
            [HilosDbContext::userRenames, HilosDbContext::secondFactorSettings],
        ));
    }
}

final class TestUserAgent extends AbstractUserAgent
{
}

final class TestUserAgentDaemon extends AbstractUserAgentDaemon
{
}
