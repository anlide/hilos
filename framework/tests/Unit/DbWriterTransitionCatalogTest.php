<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Hilos as ChatHilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\DbWriterClaim;
use Hilos\Core\TruthSource\DbWriterClaimWidth;
use Hilos\Core\TruthSource\DbWriterPartition;
use Hilos\Core\TruthSource\DbWriterTransitionCatalog;
use Hilos\Core\TruthSource\DbWriterTransitionState;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent census and versioned ceiling of every permitted DB writer state. */
final class DbWriterTransitionCatalogTest extends TestCase
{
    /** Test-local loader handle, released with this suite; no application state is retained. */
    private static ClassLoader $demoLoader;

    /** Loads the real demo facades without booting any application or its DB. */
    public static function setUpBeforeClass(): void
    {
        self::$demoLoader = new ClassLoader();
        foreach (['Chat' => 'chat', 'Tasks' => 'tasks', 'Polls' => 'polls',
            'BinanceBtcTracker' => 'binance-btc-tracker', 'EcommerceShop' => 'ecommerce-shop',
            'OnlineTesting' => 'online-testing', 'Preferans' => 'preferans'] as $namespace => $directory) {
            self::$demoLoader->addPsr4('Demo\\' . $namespace . '\\', __DIR__ . '/../../../demo/' . $directory . '/backend');
        }
        self::$demoLoader->register();
    }

    /** Releases the test's autoloader; loaded demo classes contain static metadata only. */
    public static function tearDownAfterClass(): void
    {
        self::$demoLoader->unregister();
    }

    /** @return iterable<string, array{string, bool, bool, bool, bool, bool}> Namespace and independently fixed optional roles */
    public static function profiles(): iterable
    {
        yield 'chat' => ['Demo\\Chat', true, true, true, true, true];
        yield 'tasks' => ['Demo\\Tasks', true, true, false, false, false];
        yield 'polls' => ['Demo\\Polls', true, true, false, false, false];
        yield 'binance' => ['Demo\\BinanceBtcTracker', false, true, false, false, true];
        yield 'shop' => ['Demo\\EcommerceShop', false, false, false, false, false];
        yield 'testing' => ['Demo\\OnlineTesting', false, false, false, false, false];
        yield 'preferans' => ['Demo\\Preferans', false, false, false, false, false];
    }

    /**
     * @param string $namespace Project namespace
     * @param bool $hasLegalAndOauth Registered optional roles
     * @param bool $hasSms Registered SMS worker
     * @param bool $hasPush Registered push channel
     * @param bool $isChat Project-specific history extension
     * @param bool $hasDurableSms Independently fixed durable SMS role
     */
    #[DataProvider('profiles')]
    public function testEveryStartingVectorMatchesIndependentCensus(
        string $namespace,
        bool $hasLegalAndOauth,
        bool $hasSms,
        bool $hasPush,
        bool $isChat,
        bool $hasDurableSms,
    ): void {
        $facade = $namespace . '\\Hilos';
        $roles = [
            'users' => $namespace . '\\Agents\\Hilos\\UsersLibraryAgent',
            'person' => $namespace . '\\Agents\\Hilos\\UserAgent',
            'holder' => $namespace . '\\Agents\\Hilos\\SessionsLibraryAgent',
            'notifications' => $namespace . '\\Agents\\Hilos\\NotificationsLibraryAgent',
            'export' => $namespace . '\\Agents\\Hilos\\DataExportAgent',
            'mail' => MailDeliveryChannelAgent::class,
        ];
        $roleTypes = [
            'users' => HilosAgentType::HILOS_USERS_LIBRARY,
            'person' => HilosAgentType::HILOS_USER,
            'holder' => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            'notifications' => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            'export' => HilosAgentType::HILOS_DATA_EXPORT,
            'mail' => HilosAgentType::HILOS_MAIL,
            'legal' => HilosAgentType::HILOS_LEGAL,
            'oauth' => HilosAgentType::HILOS_OAUTH,
            'sms' => HilosAgentType::HILOS_SMS,
            'push' => HilosAgentType::HILOS_PUSH,
        ];
        if ($hasLegalAndOauth) {
            $roles['legal'] = $namespace . '\\Agents\\Hilos\\DemoHilosLegalAgent';
            $roles['oauth'] = $namespace . '\\Agents\\OAuthAgent';
        }
        if ($hasSms) {
            $roles['sms'] = $hasDurableSms ? SmsDeliveryChannelAgent::class : SmsRawSendAgent::class;
        }
        if ($hasPush) {
            $roles['push'] = PushDeliveryChannelAgent::class;
        }
        foreach ($roleTypes as $role => $agentType) {
            self::assertSame($roles[$role] ?? null, AgentRegistry::workerClass($facade::AGENTS[$agentType] ?? null));
        }
        foreach ([HilosFeature::AUTH, HilosFeature::NOTIFICATIONS, HilosFeature::NOTIFICATION_DELIVERY] as $feature) {
            self::assertContains($feature, Hilos::featuresOf($facade));
        }

        // The subject stays literal: widths, operations and leaves are not taken from the provider.
        $vectors = [
            'users' => [['users', 'whole', 'add'], ['person', 'rows', 'update'], ['holder', 'whole', 'remove,update']],
            'identities' => [['users', 'whole', 'add'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove,update']],
            'verifications' => [['users', 'whole', 'add,remove,update'], ['holder', 'whole', 'remove']],
            'passkeyCredentials' => [['users', 'whole', 'add'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove,update']],
            'secondFactors' => [['users', 'whole', 'add,remove'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove,update']],
            'secondFactorBackupCodes' => [
                ['users', 'whole', 'add,remove'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove,update']],
            'secondFactorResets' => [['users', 'whole', 'add'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove']],
            'secondFactorSettings' => [['person', 'set', 'add,update'], ['holder', 'whole', 'add,remove,update']],
            'secondFactorTrusts' => [['person', 'set', 'remove,update'], ['holder', 'whole', 'add,remove,update']],
            'stepUps' => [['person', 'set', 'add,remove,update'], ['holder', 'whole', 'remove']],
            'accountDeletions' => [['users', 'whole', 'add'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'update']],
            'legalAcceptances' => [['users', 'whole', 'add'], ['person', 'set', 'remove'], ['holder', 'whole', 'remove']],
            'notifications' => [['notifications', 'whole', 'add'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove']],
            'notificationDeliveries' => [
                ['notifications', 'whole', 'add,remove,update'], ['mail', 'whole', 'update'], ['holder', 'whole', 'remove']],
            'notificationPreferences' => [['person', 'set', 'add,remove,update'], ['holder', 'whole', 'remove']],
            'pushSubscriptions' => [
                ['notifications', 'whole', 'add,update'], ['person', 'set', 'remove,update'], ['holder', 'whole', 'remove']],
            'dataExports' => [['export', 'whole', 'add,remove,update'], ['holder', 'whole', 'remove']],
            'userRenames' => [['person', 'set', 'add,update'], ['holder', 'whole', 'remove']],
            'userPhotos' => [['person', 'set', 'add,remove,update'], ['holder', 'whole', 'remove']],
        ];
        $leaves = [
            'users' => ['HIL-1952', 'HIL-1999'],
            'identities' => ['HIL-1952', 'HIL-1999'],
            'verifications' => ['HIL-1952'],
            'passkeyCredentials' => ['HIL-1952', 'HIL-1999'],
            'secondFactors' => ['HIL-1903', 'HIL-1952', 'HIL-1999'],
            'secondFactorBackupCodes' => ['HIL-1904', 'HIL-1952', 'HIL-1999'],
            'secondFactorResets' => ['HIL-1952', 'HIL-1999'],
            'secondFactorSettings' => ['HIL-1952', 'HIL-1999'],
            'secondFactorTrusts' => ['HIL-1907', 'HIL-1908', 'HIL-1909', 'HIL-1952', 'HIL-1999'],
            'stepUps' => ['HIL-1952'],
            'accountDeletions' => ['HIL-1952'],
            'legalAcceptances' => ['HIL-1952'],
            'notifications' => ['HIL-1952'],
            'notificationDeliveries' => ['HIL-1902', 'HIL-1910', 'HIL-1952'],
            'notificationPreferences' => ['HIL-1952'],
            'pushSubscriptions' => ['HIL-1895', 'HIL-1905', 'HIL-1906', 'HIL-1952'],
            'dataExports' => ['HIL-1952'],
            'userRenames' => ['HIL-1952'],
            'userPhotos' => ['HIL-1952'],
        ];
        if ($hasLegalAndOauth) {
            $vectors['identities'][] = ['oauth', 'whole', 'add'];
            $vectors['legalAcceptanceExports'] = [['legal', 'whole', 'add,remove,update'], ['holder', 'whole', 'remove']];
            $leaves['legalAcceptanceExports'] = ['HIL-1952'];
        }
        foreach (['sms' => $hasDurableSms, 'push' => $hasPush] as $role => $present) {
            if ($present) {
                $vectors['notificationDeliveries'][] = [$role, 'whole', 'update'];
            }
        }
        if ($isChat) {
            $roles['chat'] = 'Demo\\Chat\\Agents\\ChatAgent';
            self::assertSame($roles['chat'], AgentRegistry::workerClass($facade::AGENTS['chat']));
            $vectors['userRenames'][] = ['chat', 'whole', 'update'];
            $leaves['userRenames'] = ['HIL-1897', 'HIL-1952'];
            $vectors['events'] = [['chat', 'whole', 'add,remove,update'], ['users', 'whole', 'add'], ['holder', 'whole', 'remove']];
            $vectors['eventUserRegistrations'] = [
                ['chat', 'whole', 'add,remove,update'], ['users', 'whole', 'add'], ['holder', 'whole', 'remove']];
            $vectors['eventMessages'] = [['chat', 'whole', 'add,remove,update'], ['holder', 'whole', 'remove,update']];
            $vectors['eventAttachments'] = [['chat', 'whole', 'add,remove,update'], ['holder', 'whole', 'remove']];
            $leaves['events'] = ['HIL-1952'];
            $leaves['eventUserRegistrations'] = ['HIL-1952'];
            $leaves['eventMessages'] = ['HIL-1952', 'HIL-1999'];
            $leaves['eventAttachments'] = ['HIL-1952'];
        }
        ksort($vectors);
        $catalog = $facade::getDbWriterTransitions();
        self::assertSame(array_keys($vectors), array_keys($catalog));
        $census = [];
        foreach ($facade::AGENTS as $entry) {
            $worker = AgentRegistry::workerClass($entry);
            self::assertNotNull($worker);
            self::assertSame(is_subclass_of($worker, AbstractDeliveryChannelAgent::class)
                ? DbWriterPartition::NotificationChannelShard : null, $worker::DB_WRITER_PARTITION);
            foreach (DbWriterClaim::declarationsOf($worker) as $collection => $claims) {
                foreach ($claims as $claim) {
                    $census[$collection][$claim->ownerClass . ':' . $claim->width->value] = $claim;
                }
            }
        }
        $conflicts = [];
        foreach ($census as $collection => $claims) {
            $changing = array_filter($claims, static fn (DbWriterClaim $claim): bool =>
                $claim->operations->allows(TruthSourceOperation::Update) || $claim->operations->allows(TruthSourceOperation::Remove));
            if (count($changing) > 1) {
                $conflicts[] = $collection;
            }
        }
        sort($conflicts);
        self::assertSame(array_keys($vectors), $conflicts, 'Full registered-agent census');
        foreach ($vectors as $collection => $vector) {
            $expected = [];
            foreach ($vector as [$role, $width, $operations]) {
                $expected[] = $roles[$role] . ':' . $width . ':' . $operations;
            }
            sort($expected);
            self::assertSame($leaves[$collection], $catalog[$collection][0]->pendingLeaves, $collection);
            self::assertSame($collection === 'notificationDeliveries' ? [DbWriterPartition::NotificationChannelShard] : [],
                $catalog[$collection][0]->partitions, $collection);
            self::assertSame($expected, $this->signatures($catalog[$collection][0]->claims), $collection . ' catalog');
            self::assertSame($expected, $this->signatures(array_values($census[$collection])), $collection . ' declarations');
            $keys = [];
            foreach ($catalog[$collection] as $state) {
                $canonical = array_values(array_unique($state->pendingLeaves));
                sort($canonical);
                self::assertSame($canonical, $state->pendingLeaves, $collection . ' canonical leaves');
                self::assertSame([], array_diff($state->pendingLeaves, $leaves[$collection]), $collection . ' known leaves');
                self::assertSame([], array_intersect($state->pendingLeaves, ['HIL-1894', 'HIL-1900', 'HIL-1901']));
                $keys[] = implode(',', $state->pendingLeaves);
            }
            self::assertSame($keys, array_values(array_unique($keys)), $collection . ' unique states');
            self::assertSame([], $catalog[$collection][array_key_last($catalog[$collection])]->pendingLeaves, $collection . ' final');
        }
    }

    /** Only unlink still waits for its personal-owner cutover before terminal erasure. */
    public function testRenameOnlyUnlinkStillWaitsForItsCutover(): void
    {
        $states = $this->statesByPendingLeaves(ChatHilos::getDbWriterTransitions()['userRenames']);
        self::assertCount(3, $states);
        $this->assertVector([
            ChatAgent::class . ':whole:update',
            UserAgent::class . ':set:add,update',
            SessionsLibraryAgent::class . ':whole:remove',
        ], $states['HIL-1897,HIL-1952']);
        $this->assertVector([
            UserAgent::class . ':set:add,update',
            SessionsLibraryAgent::class . ':whole:remove',
        ], $states['HIL-1952']);
        $this->assertVector([UserAgent::class . ':set:add,remove,update'], $states['']);
    }

    /** Every remaining push upsert or dead-endpoint path keeps the library's shared Update. */
    public function testPushUpdateSurvivesUntilItsLastOrdinaryUse(): void
    {
        $states = $this->statesByPendingLeaves(ChatHilos::getDbWriterTransitions()['pushSubscriptions']);
        self::assertSame([
            '', 'HIL-1895,HIL-1905,HIL-1906,HIL-1952', 'HIL-1895,HIL-1905,HIL-1952',
            'HIL-1895,HIL-1906,HIL-1952', 'HIL-1895,HIL-1952',
            'HIL-1905,HIL-1906,HIL-1952', 'HIL-1905,HIL-1952', 'HIL-1906,HIL-1952', 'HIL-1952',
        ], array_keys($states));
        foreach ($states as $pending => $state) {
            $expected = [UserAgent::class . ':set:remove,update'];
            if ($pending !== '') {
                $expected[] = SessionsLibraryAgent::class . ':whole:remove';
            }
            $expected[] = NotificationsLibraryAgent::class . ($pending === '' || $pending === 'HIL-1952'
                ? ':whole:add' : ':whole:add,update');
            $this->assertVector($expected, $state);
        }
    }

    /** Renewal and policy paths share Update; terminal deletion keeps Remove after ordinary work. */
    public function testTrustSharedUsesAndTerminalRights(): void
    {
        $states = $this->statesByPendingLeaves(ChatHilos::getDbWriterTransitions()['secondFactorTrusts']);
        self::assertSame([
            '', 'HIL-1907,HIL-1908,HIL-1909,HIL-1952,HIL-1999', 'HIL-1907,HIL-1908,HIL-1952,HIL-1999',
            'HIL-1907,HIL-1909,HIL-1952,HIL-1999', 'HIL-1907,HIL-1952,HIL-1999',
            'HIL-1908,HIL-1909,HIL-1952,HIL-1999', 'HIL-1908,HIL-1952,HIL-1999',
            'HIL-1909,HIL-1952,HIL-1999', 'HIL-1952,HIL-1999', 'HIL-1999',
        ], array_keys($states));
        foreach ($states as $pending => $state) {
            $operations = match ($pending) {
                '' => 'add',
                'HIL-1909,HIL-1952,HIL-1999', 'HIL-1952,HIL-1999', 'HIL-1999' => 'add,remove',
                default => 'add,remove,update',
            };
            $this->assertVector([
                UserAgent::class . ':set:remove,update',
                SessionsLibraryAgent::class . ':whole:' . $operations,
            ], $state);
        }
    }

    /** Retry and retention follow the partition guard; only retention grants channel Remove. */
    public function testDeliveryMovesRequireGuardAndKeepUnpaidLibraryOperations(): void
    {
        $states = $this->statesByPendingLeaves(ChatHilos::getDbWriterTransitions()['notificationDeliveries']);
        $expectedLibrary = [
            '' => 'add',
            'HIL-1902,HIL-1910,HIL-1952' => 'add,remove,update',
            'HIL-1902,HIL-1952' => 'add,remove',
            'HIL-1910,HIL-1952' => 'add,update',
            'HIL-1952' => 'add',
        ];
        self::assertSame(array_keys($expectedLibrary), array_keys($states));
        foreach ($expectedLibrary as $pending => $operations) {
            $expected = [NotificationsLibraryAgent::class . ':whole:' . $operations];
            foreach ([MailDeliveryChannelAgent::class, SmsDeliveryChannelAgent::class, PushDeliveryChannelAgent::class] as $channel) {
                $expected[] = $channel . (str_contains($pending, 'HIL-1902') ? ':whole:update' : ':whole:remove,update');
            }
            if ($pending !== '') {
                $expected[] = SessionsLibraryAgent::class . ':whole:remove';
            }
            $this->assertVector($expected, $states[$pending]);
            self::assertSame([DbWriterPartition::NotificationChannelShard],
                $states[$pending]->partitions);
        }
    }

    public function testRawWorkersNeverAcquireFutureDeliveryRemoveOrAnOldGuardVertex(): void
    {
        foreach (['Demo\\Tasks\\Hilos', 'Demo\\Polls\\Hilos'] as $facade) {
            foreach ($facade::getDbWriterTransitions()['notificationDeliveries'] as $state) {
                self::assertNotContains('HIL-1893', $state->pendingLeaves);
                self::assertSame([DbWriterPartition::NotificationChannelShard], $state->partitions);
                foreach ($state->claims as $claim) {
                    self::assertNotSame(SmsRawSendAgent::class, $claim->ownerClass);
                }
            }
        }
    }

    /** Erasure cannot retire ordinary work or the grants still used by the old merge. */
    public function testTerminalCutoversKeepExactMergeRightsAndFinalPersonalGrants(): void
    {
        $catalog = ChatHilos::getDbWriterTransitions();
        $users = $this->statesByPendingLeaves($catalog['users']);
        self::assertSame(['', 'HIL-1952,HIL-1999', 'HIL-1999'], array_keys($users));
        $this->assertVector([
            UsersLibraryAgent::class . ':whole:add', UserAgent::class . ':rows:update',
            SessionsLibraryAgent::class . ':whole:remove,update',
        ], $users['HIL-1952,HIL-1999']);
        $this->assertVector([
            UsersLibraryAgent::class . ':whole:add', UserAgent::class . ':rows:remove,update',
            SessionsLibraryAgent::class . ':whole:update',
        ], $users['HIL-1999']);
        $this->assertVector([
            UsersLibraryAgent::class . ':whole:add', UserAgent::class . ':rows:remove,update',
        ], $users['']);
        foreach ([
            'identities' => 'remove,update', 'passkeyCredentials' => 'update',
            'secondFactors' => 'remove,update', 'secondFactorBackupCodes' => 'remove,update',
            'secondFactorResets' => 'remove', 'secondFactorSettings' => 'add,remove,update',
            'secondFactorTrusts' => 'add,remove', 'eventMessages' => 'update',
        ] as $collection => $mergeOperations) {
            $states = $this->statesByPendingLeaves($catalog[$collection]);
            $holder = array_values(array_filter($states['HIL-1999']->claims,
                static fn (DbWriterClaim $claim): bool => $claim->ownerClass === SessionsLibraryAgent::class));
            self::assertSame([SessionsLibraryAgent::class . ':whole:' . $mergeOperations], $this->signatures($holder), $collection);
        }
        foreach (['stepUps', 'userRenames', 'accountDeletions', 'events', 'eventAttachments', 'eventUserRegistrations'] as $collection) {
            $states = $this->statesByPendingLeaves($catalog[$collection]);
            self::assertArrayNotHasKey('HIL-1999', $states, $collection . ' has no merge debt');
            foreach ($states['']->claims as $claim) {
                self::assertNotSame(SessionsLibraryAgent::class, $claim->ownerClass, $collection . ' final owner');
            }
        }
        $legal = $this->statesByPendingLeaves($catalog['legalAcceptances']);
        self::assertSame(['', 'HIL-1952'], array_keys($legal));
        $this->assertVector([
            UsersLibraryAgent::class . ':whole:add', UserAgent::class . ':set:remove',
            SessionsLibraryAgent::class . ':whole:remove',
        ], $legal['HIL-1952']);
        $settings = $this->statesByPendingLeaves($catalog['secondFactorSettings']);
        $this->assertVector([UserAgent::class . ':set:add,remove,update'], $settings['']);
    }

    public function testTypedValuesAndCanonicalLeaves(): void
    {
        $claim = new DbWriterClaim(AbstractAgent::class, DbWriterClaimWidth::Rows, TruthSourceOperations::of(TruthSourceOperation::Update));
        $state = new DbWriterTransitionState(['HIL-1999', 'HIL-1952', 'HIL-1952'], [$claim], [DbWriterPartition::NotificationChannelShard]);
        self::assertSame(['HIL-1952', 'HIL-1999'], $state->pendingLeaves);
        self::assertSame([$claim], $state->claims);
        self::assertSame('rows', $claim->width->value);
        self::assertSame('notification-channel-shard', $state->partitions[0]->value);
        self::assertSame([TruthSourceOperation::Update], $claim->operations->asList());
        self::assertSame(['notification-channel-shard'], array_map(
            static fn (DbWriterPartition $partition): string => $partition->value, DbWriterPartition::cases(),
        ));
        self::assertNull(DbWriterPartition::tryFrom('unknown-partition'));
    }

    public function testAbsentOptionalRolesAndInactiveFeaturesDoNotInventOrHideClaims(): void
    {
        self::assertSame([], CatalogEmptyHilos::getDbWriterTransitions());
        self::assertSame([], Hilos::featuresOf(CatalogNoFeatureHilos::class));
        $catalog = CatalogNoFeatureHilos::getDbWriterTransitions();
        self::assertArrayNotHasKey('legalAcceptanceExports', $catalog);
        self::assertArrayNotHasKey('dataExports', $catalog);
        self::assertArrayNotHasKey('notificationDeliveries', $catalog);
        self::assertSame(['HIL-1952', 'HIL-1999'], $catalog['identities'][0]->pendingLeaves);
        self::assertSame([
            AbstractSessionsLibraryAgent::class . ':whole:remove,update',
            AbstractUserAgent::class . ':set:remove,update',
        ], $this->signatures($catalog['identities'][0]->claims));

        self::assertSame([], Hilos::featuresOf(CatalogRegisteredButInactiveHilos::class));
        $inactive = CatalogRegisteredButInactiveHilos::getDbWriterTransitions();
        $this->assertVector([
            AbstractUsersLibraryAgent::class . ':whole:add',
            AbstractUserAgent::class . ':set:remove,update',
            AbstractSessionsLibraryAgent::class . ':whole:remove,update',
        ], $inactive['identities'][0]);
        $this->assertVector([
            AbstractNotificationsLibraryAgent::class . ':whole:add,remove,update',
            MailDeliveryChannelAgent::class . ':whole:update',
            AbstractSessionsLibraryAgent::class . ':whole:remove',
        ], $inactive['notificationDeliveries'][0]);
        foreach (['legalAcceptanceExports', 'dataExports', 'settings', 'probeValues', 'sessions', 'accessLogEntries', 'userMerges'] as $key) {
            self::assertArrayNotHasKey($key, $inactive);
        }
    }

    /**
     * Compares the entire graph, including future states, against an independently written ceiling.
     * State keys also fix every edge: the only move is payment of one pending leaf. Equal-size
     * class, width, operation, partition or edge substitutions therefore change this comparison.
     * A consumer switched while its shared grant stays unchanged owes proof in its own leaf.
     *
     * @param string $namespace Project namespace
     * @param bool $hasLegalAndOauth Fixed optional role profile
     * @param bool $hasSms Fixed SMS registration
     * @param bool $hasPush Fixed push registration
     * @param bool $isChat Fixed history extension
     * @param bool $hasDurableSms Independently fixed durable SMS role
     */
    #[DataProvider('profiles')]
    public function testWholeCatalogMatchesVersionOneCeiling(
        string $namespace,
        bool $hasLegalAndOauth,
        bool $hasSms,
        bool $hasPush,
        bool $isChat,
        bool $hasDurableSms,
    ): void {
        $expected = $this->ceilingV1($hasLegalAndOauth, $isChat);
        $roles = [
            'users' => [$namespace . '\\Agents\\Hilos\\UsersLibraryAgent'],
            'person' => [$namespace . '\\Agents\\Hilos\\UserAgent'],
            'holder' => [$namespace . '\\Agents\\Hilos\\SessionsLibraryAgent'],
            'notifications' => [$namespace . '\\Agents\\Hilos\\NotificationsLibraryAgent'],
            'export' => [$namespace . '\\Agents\\Hilos\\DataExportAgent'],
            'legal' => [$namespace . '\\Agents\\Hilos\\DemoHilosLegalAgent'],
            'oauth' => [$namespace . '\\Agents\\OAuthAgent'],
            'chat' => ['Demo\\Chat\\Agents\\ChatAgent'],
            'channel' => [MailDeliveryChannelAgent::class,
                ...($hasDurableSms ? [SmsDeliveryChannelAgent::class] : []),
                ...($hasPush ? [PushDeliveryChannelAgent::class] : [])],
        ];
        foreach ($expected as &$states) {
            foreach ($states as &$vector) {
                $expanded = [];
                foreach ($vector as $signature) {
                    [$role, $rest] = explode(':', $signature, 2);
                    if ($role === 'partition') {
                        $expanded[] = $signature;
                        continue;
                    }
                    foreach ($roles[$role] as $class) {
                        $expanded[] = $class . ':' . $rest;
                    }
                }
                sort($expanded, SORT_STRING);
                $vector = $expanded;
            }
            unset($vector);
            ksort($states, SORT_STRING);
        }
        unset($states);
        ksort($expected, SORT_STRING);

        $facade = $namespace . '\\Hilos';
        $actual = [];
        foreach ($facade::getDbWriterTransitions() as $collection => $states) {
            foreach ($states as $state) {
                $key = implode(',', $state->pendingLeaves);
                self::assertArrayNotHasKey($key, $actual[$collection] ?? [], $collection . ' duplicate state');
                $actual[$collection][$key] = $this->signatures($state->claims);
                foreach ($state->partitions as $partition) {
                    $actual[$collection][$key][] = 'partition:' . $partition->value;
                }
                sort($actual[$collection][$key], SORT_STRING);
            }
            ksort($actual[$collection], SORT_STRING);
        }
        ksort($actual, SORT_STRING);
        self::assertSame($expected, $actual, 'Version 1: change only with the corresponding live cutover');
        $facade::validateTopology();
    }

    /**
     * Literal permitted vectors, not operation reasons or a second transition enumerator.
     * Every omitted subset is forbidden, including early terminal moves and transfers before
     * the delivery guard. Optional-role expansion changes class names alone. A real cutover
     * must delete its paid states here together with the production states and active key.
     *
     * @param bool $hasLegalAndOauth Independently fixed optional roles
     * @param bool $isChat Whether the profile includes the history owner
     * @return array<string, array<string, list<string>>> Collection => pending leaves => exact signatures
     */
    private function ceilingV1(bool $hasLegalAndOauth, bool $isChat): array
    {
        $ceiling = [
            'users' => [
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:rows:update', 'holder:whole:remove,update'],
                'HIL-1999' => ['users:whole:add', 'person:rows:remove,update', 'holder:whole:update'],
                '' => ['users:whole:add', 'person:rows:remove,update'],
            ],
            'identities' => [
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'verifications' => [
                'HIL-1952' => ['users:whole:add,remove,update', 'holder:whole:remove'],
                '' => ['users:whole:add,remove,update'],
            ],
            'passkeyCredentials' => [
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:update'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'secondFactors' => [
                'HIL-1903,HIL-1952,HIL-1999' => [
                    'users:whole:add,remove', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'secondFactorBackupCodes' => [
                'HIL-1904,HIL-1952,HIL-1999' => [
                    'users:whole:add,remove', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                'HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove,update'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'secondFactorResets' => [
                'HIL-1952,HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1999' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:remove'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'secondFactorSettings' => [
                'HIL-1952,HIL-1999' => ['person:set:add,update', 'holder:whole:add,remove,update'],
                'HIL-1999' => ['person:set:add,remove,update', 'holder:whole:add,remove,update'],
                '' => ['person:set:add,remove,update'],
            ],
            'secondFactorTrusts' => [
                'HIL-1907,HIL-1908,HIL-1909,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1907,HIL-1908,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1907,HIL-1909,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1907,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1908,HIL-1909,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1908,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove,update'],
                'HIL-1909,HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove'],
                'HIL-1952,HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove'],
                'HIL-1999' => ['person:set:remove,update', 'holder:whole:add,remove'],
                '' => ['person:set:remove,update', 'holder:whole:add'],
            ],
            'stepUps' => [
                'HIL-1952' => ['person:set:add,remove,update', 'holder:whole:remove'],
                '' => ['person:set:add,remove,update'],
            ],
            'accountDeletions' => [
                'HIL-1952' => ['users:whole:add', 'person:set:remove,update', 'holder:whole:update'],
                '' => ['users:whole:add', 'person:set:remove,update'],
            ],
            'legalAcceptances' => [
                'HIL-1952' => ['users:whole:add', 'person:set:remove', 'holder:whole:remove'],
                '' => ['users:whole:add', 'person:set:remove'],
            ],
            'notifications' => [
                'HIL-1952' => ['notifications:whole:add', 'person:set:remove,update', 'holder:whole:remove'],
                '' => ['notifications:whole:add', 'person:set:remove,update'],
            ],
            'notificationDeliveries' => [
                'HIL-1902,HIL-1910,HIL-1952' => [
                    'notifications:whole:add,remove,update', 'channel:whole:update', 'holder:whole:remove',
                    'partition:notification-channel-shard'],
                'HIL-1902,HIL-1952' => [
                    'notifications:whole:add,remove', 'channel:whole:update', 'holder:whole:remove',
                    'partition:notification-channel-shard'],
                'HIL-1910,HIL-1952' => [
                    'notifications:whole:add,update', 'channel:whole:remove,update', 'holder:whole:remove',
                    'partition:notification-channel-shard'],
                'HIL-1952' => [
                    'notifications:whole:add', 'channel:whole:remove,update', 'holder:whole:remove',
                    'partition:notification-channel-shard'],
                '' => ['notifications:whole:add', 'channel:whole:remove,update', 'partition:notification-channel-shard'],
            ],
            'notificationPreferences' => [
                'HIL-1952' => ['person:set:add,remove,update', 'holder:whole:remove'],
                '' => ['person:set:add,remove,update'],
            ],
            'pushSubscriptions' => [
                'HIL-1895,HIL-1905,HIL-1906,HIL-1952' => [
                    'notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1895,HIL-1905,HIL-1952' => [
                    'notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1895,HIL-1906,HIL-1952' => [
                    'notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1895,HIL-1952' => ['notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1905,HIL-1906,HIL-1952' => [
                    'notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1905,HIL-1952' => ['notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1906,HIL-1952' => ['notifications:whole:add,update', 'person:set:remove,update', 'holder:whole:remove'],
                'HIL-1952' => ['notifications:whole:add', 'person:set:remove,update', 'holder:whole:remove'],
                '' => ['notifications:whole:add', 'person:set:remove,update'],
            ],
            'dataExports' => [
                'HIL-1952' => ['export:whole:add,remove,update', 'holder:whole:remove'],
                '' => ['export:whole:add,remove,update'],
            ],
            'userRenames' => [
                'HIL-1952' => ['person:set:add,update', 'holder:whole:remove'],
                '' => ['person:set:add,remove,update'],
            ],
            'userPhotos' => [
                'HIL-1952' => ['person:set:add,remove,update', 'holder:whole:remove'],
                '' => ['person:set:add,remove,update'],
            ],
        ];
        if ($hasLegalAndOauth) {
            foreach ($ceiling['identities'] as &$vector) {
                $vector[] = 'oauth:whole:add';
            }
            unset($vector);
            $ceiling['legalAcceptanceExports'] = [
                'HIL-1952' => ['legal:whole:add,remove,update', 'holder:whole:remove'],
                '' => ['legal:whole:add,remove,update'],
            ];
        }
        if ($isChat) {
            $ceiling['userRenames'] = [
                'HIL-1897,HIL-1952' => ['chat:whole:update', 'person:set:add,update', 'holder:whole:remove'],
                'HIL-1952' => ['person:set:add,update', 'holder:whole:remove'],
                '' => ['person:set:add,remove,update'],
            ];
            $ceiling['events'] = [
                'HIL-1952' => ['chat:whole:add,remove,update', 'users:whole:add', 'holder:whole:remove'],
                '' => ['chat:whole:add,remove,update', 'users:whole:add'],
            ];
            $ceiling['eventUserRegistrations'] = [
                'HIL-1952' => ['chat:whole:add,remove,update', 'users:whole:add', 'holder:whole:remove'],
                '' => ['chat:whole:add,remove,update', 'users:whole:add'],
            ];
            $ceiling['eventMessages'] = [
                'HIL-1952,HIL-1999' => ['chat:whole:add,remove,update', 'holder:whole:remove,update'],
                'HIL-1999' => ['chat:whole:add,remove,update', 'holder:whole:update'],
                '' => ['chat:whole:add,remove,update'],
            ];
            $ceiling['eventAttachments'] = [
                'HIL-1952' => ['chat:whole:add,remove,update', 'holder:whole:remove'],
                '' => ['chat:whole:add,remove,update'],
            ];
        }

        return $ceiling;
    }

    /**
     * @param list<DbWriterTransitionState> $states Closed states to address independently by their pending key
     * @return array<string, DbWriterTransitionState> States in lexical key order
     */
    private function statesByPendingLeaves(array $states): array
    {
        $byPending = [];
        foreach ($states as $state) {
            $byPending[implode(',', $state->pendingLeaves)] = $state;
        }
        ksort($byPending, SORT_STRING);

        return $byPending;
    }

    /**
     * @param list<string> $expected Independent exact class/width/operation signatures
     * @param DbWriterTransitionState $state Catalog state under test
     */
    private function assertVector(array $expected, DbWriterTransitionState $state): void
    {
        sort($expected);
        self::assertSame($expected, $this->signatures($state->claims), implode(',', $state->pendingLeaves));
    }

    /**
     * @param list<DbWriterClaim> $claims Claims to compare without operation-order dependence
     * @return list<string> Sorted exact class/width/operation signatures
     */
    private function signatures(array $claims): array
    {
        $signatures = [];
        foreach ($claims as $claim) {
            $operations = array_map(static fn (TruthSourceOperation $operation): string => $operation->value, $claim->operations->asList());
            sort($operations);
            $signatures[] = $claim->ownerClass . ':' . $claim->width->value . ':' . implode(',', $operations);
        }
        sort($signatures);

        return $signatures;
    }
}

abstract class CatalogEmptyHilos extends Hilos
{
}

abstract class CatalogNoFeatureHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => AbstractUserAgent::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => AbstractSessionsLibraryAgent::class],
    ];
}

abstract class CatalogRegisteredButInactiveHilos extends CatalogNoFeatureHilos
{
    public const array AGENTS = [
        ...parent::AGENTS,
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => AbstractUsersLibraryAgent::class],
        HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY => [AgentRegistryKey::WORKER => AbstractNotificationsLibraryAgent::class],
        HilosAgentType::HILOS_MAIL => [AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class],
    ];
}
