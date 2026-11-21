<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Generator;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Database;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Users\AdminAudience;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Core\Router\SignalSource;
use Hilos\Constants\SignalConstants;

/** Existing route declarations and personal reset-wait ownership beside the live merge suites. */
final class AccountMergeCommandRouteIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    public const int SURVIVOR_USER_ID = 11;
    public const int LOSER_USER_ID = 12;
    public const int ADMIN_USER_ID = 14;
    public const string ACCEPT_KEY = 'accept-1';
    public const string ADMIN_SESSION_TOKEN = 'ad0000000000000000000000000001275';

    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        parent::tearDown();
    }

    public function testTheWireNameDeclaresTheRouteOnTheLibrary(): void
    {
        self::assertContains(CliCommands::ACCOUNT_MERGE, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
    }

    public function testTheBrowsersFrameDeclaresTheSignalOnTheLibrary(): void
    {
        self::assertSame(AccountMergeSignalData::class,
            AbstractSessionsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_ACCOUNT_MERGE]);
    }

    public function testATombstoneWithoutPreparedFinishingDoesNotStopThePersonalOwner(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (11, 'Survivor'), (12, 'Loser')");
        $agent = $this->personAgent(self::LOSER_USER_ID);
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [self::LOSER_USER_ID, self::SURVIVOR_USER_ID, TimeHelper::nowMs()]);
        $row = Database::sql('SELECT * FROM `hilos_user_merge` WHERE `user_id` = ?', [self::LOSER_USER_ID])->firstRow();
        $this->asPerson(self::LOSER_USER_ID, static fn (AbstractUserAgent $agent) => $agent->onSignalDbSyncCreated(
            new DbSyncCreatedSignalData(HilosDbContext::userMerges, (string)self::LOSER_USER_ID, $row),
            SignalSource::DB, SignalConstants::DB_SYNC_CREATED,
        ));

        self::assertFalse($agent->shouldStop());
    }

    public function testThePersonsAgentWritesAndRemovesItsOwnWaitButNotAnothers(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (11, 'Survivor'), (12, 'Loser')");
        $now = TimeHelper::nowMs();
        Database::sqlRun('INSERT INTO `hilos_second_factor_setting`'
            . ' (`user_id`, `reset_wait_days`, `pending_reset_wait_days`, `pending_reset_wait_from`, `updated_at`)'
            . ' VALUES (?, 20, 1, ?, ?)', [self::LOSER_USER_ID, $now, $now]);
        $this->asPerson(self::SURVIVOR_USER_ID, function (): void {
            Hilos::$db->secondFactorSettings->actions->setResetWait(self::SURVIVOR_USER_ID, 20, null, null);
            Hilos::$db->secondFactorSettings->actions->setResetWait(self::SURVIVOR_USER_ID, 30, null, null);
            self::assertSame(30, Hilos::$db->secondFactorSettings[self::SURVIVOR_USER_ID]->resetWaitDays);
            Hilos::$db->secondFactorSettings->actions->deleteForUser(self::SURVIVOR_USER_ID);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_second_factor_setting` WHERE `user_id` = ?',
                [self::SURVIVOR_USER_ID])->rows());
            try {
                Hilos::$db->secondFactorSettings->actions->deleteForUser(self::LOSER_USER_ID);
                self::fail("The person's agent removed another person's wait");
            } catch (WriteNotAllowedException) {
                self::assertCount(1, Database::sql('SELECT * FROM `hilos_second_factor_setting` WHERE `user_id` = ?',
                    [self::LOSER_USER_ID])->rows());
            }
        });
    }
}

/**
 * Runtime holding the administrator's one tab, the browser every browser merge is asked from (HIL-1275).
 */
final class AccountMergeRouteTestRtContext extends RtContext
{
    /**
     * Mounts the administrator's tab.
     */
    public function configure(): void
    {
        $connections = AccountMergeRouteTestConnections::init();
        $connections->add(AccountMergeRouteTestConnection::create(
            AccountMergeCommandRouteIntegrationTest::ACCEPT_KEY,
            AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID,
            AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN,
        ));
        $this->_stateCollections[AccountMergeRouteTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Session-stage connection collection of the fixture.
 */
final class AccountMergeRouteTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'accountMergeRouteTestConnections';
    public const string STATE_CLASS = AccountMergeRouteTestConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class AccountMergeRouteTestConnection extends HilosSessionConnection
{
    /**
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Incoming field changes
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

/** The fixture's administrators, declared by the case rather than read from a table. */
final class AccountMergeRouteTestAdminAudience extends AdminAudience
{
    /** @var list<int> Active administrators */
    public static array $ids = [AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID];

    /**
     * @return list<int> Active administrators
     */
    protected static function userIds(): array
    {
        return self::$ids;
    }
}

/**
 * Facade of the fixture: the framework's own everything, with the fixture's administrators.
 */
abstract class AccountMergeRouteTestHilos extends Hilos
{
    protected const string ADMIN_AUDIENCE = AccountMergeRouteTestAdminAudience::class;
}

/**
 * The step-up catalog of an installation that switched the merge off.
 */
final class AccountMergeRouteTestMergeOffCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        $catalog = StepUpSettingsCatalog::getCatalog();
        $catalog[StepUpSettings::DISABLED_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]
            = StepUpOperationKey::MERGE_ACCOUNTS;

        return $catalog;
    }
}
