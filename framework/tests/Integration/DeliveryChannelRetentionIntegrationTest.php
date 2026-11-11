<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogRetention;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Adds the retention facade and accessor to the existing ownership environment before rollback starts. */
abstract class DeliveryRetentionIntegrationTestCase extends NotificationDeliveryOwnershipIntegrationTestCase
{
    private ?SettingsAccessor $previousSetting = null;

    /** The inherited environment already raises hilos_setting through its unchanged migration stub. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousSetting = Hilos::$setting;
        parent::prepareIntegrationEnvironment();
        DeliveryRetentionHilos::initBrowser();
        Hilos::$setting = new SettingsAccessor(DeliveryRetentionSettingsCatalog::class);
    }

    /** Restores the settings accessor beside the facade, claims and bus restored by the harness. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            parent::releaseIntegrationEnvironment();
        } finally {
            Hilos::$setting = $this->previousSetting;
        }
    }
}

/** Exercises the channel's real cron, paging and item actions inside the ownership harness. */
#[IntegrationTest]
final class DeliveryChannelRetentionIntegrationTest extends DeliveryRetentionIntegrationTestCase
{
    public function testOnlyTerminalRowsStrictlyBeforeTheCapturedCutoffAreDeleted(): void
    {
        $agent = new OwnershipMailAgent('1');
        $this->insertRows(25);
        $this->startPass($agent);
        $cutoff = new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent);
        self::assertIsInt($cutoff);
        $this->insertDelivery(DeliveryStatus::SENT, $cutoff - 1);
        $this->insertDelivery(DeliveryStatus::FAILED, $cutoff - 1);
        $pending = $this->insertDelivery(DeliveryStatus::PENDING, $cutoff - 1);
        $boundary = $this->insertDelivery(DeliveryStatus::SENT, $cutoff);
        $fresh = $this->insertDelivery(DeliveryStatus::FAILED, $cutoff + 1);

        $this->tick($agent);

        self::assertSame([$pending, $boundary, $fresh], $this->deliveryIds());
        self::assertNull(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent));
    }

    /** @return iterable<string, array{int}> Nonpositive ages disable retention */
    public static function disabledRetention(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /** @param int $days Persisted retention policy */
    #[DataProvider('disabledRetention')]
    public function testNonpositivePolicyDoesNotStartAPass(int $days): void
    {
        $this->insertRetention($days);
        $id = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $agent = new OwnershipMailAgent('1');
        $this->startPass($agent);
        $this->tick($agent);

        self::assertSame([$id], $this->deliveryIds());
        self::assertNull(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent));
    }

    public function testSixtyRowsAreRemovedInBoundedTicksAndThePassEnds(): void
    {
        $this->insertRows(60);
        $agent = new OwnershipMailAgent('1');
        $this->startPass($agent);
        self::assertCount(35, $this->deliveryIds());
        $this->tick($agent);
        self::assertCount(10, $this->deliveryIds());
        $this->tick($agent);
        self::assertSame([], $this->deliveryIds());
        self::assertNull(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent));

        $later = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $this->tick($agent);
        self::assertSame([$later], $this->deliveryIds(), 'A completed pass must wait for another cron firing');
    }

    public function testForeignShardsAdvanceTheCursorAndEachOwnerDeletesOnlyItsRows(): void
    {
        $foreign = $this->insertRows(30, routingKey: 1);
        $sms = $this->insertDelivery(DeliveryStatus::SENT, 1, SmsDeliveryChannel::NAME);
        $own = $this->insertRows(5);
        $first = new OwnershipMailAgent('1');
        $this->startPass($first);
        self::assertSame([...$foreign, $sms, ...$own], $this->deliveryIds());
        $this->tick($first);
        self::assertSame([...$foreign, $sms], $this->deliveryIds());

        $second = new OwnershipMailAgent('2');
        $this->startPass($second);
        self::assertCount(6, $this->deliveryIds());
        $this->tick($second);
        self::assertSame([$sms], $this->deliveryIds());

        $this->startPass(new OwnershipSmsAgent('1'));
        self::assertSame([], $this->deliveryIds());
    }

    public function testOrphanIsDeletedWithoutLoadingItsFormerNotification(): void
    {
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1902, 'Retention recipient')");
        Database::sql(
            'INSERT INTO hilos_notification (id, user_id, type, title, created_at) VALUES (11, 1902, ?, ?, 1)',
            ['test.retention', 'Retention notification'],
        );
        $this->insertDelivery(DeliveryStatus::SENT, 1, routingKey: null);
        Database::sql('DELETE FROM hilos_notification WHERE id = 11');
        // A legacy NULL key falls back to notification id 11, which belongs to shard 2 at width 2.
        $this->startPass(new OwnershipMailAgent('2'));

        self::assertSame([], $this->deliveryIds());
        self::assertSame([], Database::sql('SELECT id FROM hilos_notification')->rows());
    }

    public function testDisabledChannelStillPrunesWithoutOpeningATransport(): void
    {
        self::assertFalse(Hilos::$setting[DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME)]->bool());
        $this->insertRows(3);
        $agent = $this->getMockBuilder(MailDeliveryChannelAgent::class)
            ->setConstructorArgs(['1'])->onlyMethods(['createTransport'])->getMock();
        $agent->expects(self::never())->method('createTransport');
        $this->startPass($agent);

        self::assertSame([], $this->deliveryIds());
        self::assertSame([], new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'inFlight')->getValue($agent));
        self::assertSame([], new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'ops')->getValue($agent));
    }

    public function testStartDoesNotCatchUpButTheNextRunRemovesSeveralDaysOfBacklog(): void
    {
        $oldest = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $nextDay = $this->insertDelivery(DeliveryStatus::FAILED, 86400001);
        $agent = new OwnershipMailAgent('1');
        $this->asClaimant($agent->getId(), $agent->onStart(...));
        $this->tick($agent);
        self::assertSame([$oldest, $nextDay], $this->deliveryIds());
        $rule = $agent->armCronRuleForTest('hilos_delivery_log_prune');
        self::assertNotNull($rule);
        self::assertSame('20 3 * * *', $rule->expression);
        $this->tick($agent);

        self::assertSame([], $this->deliveryIds());
        self::assertFalse($rule->isArmedForNextCheck());
    }

    public function testFreezeDropsThePassAndTheNextRunStartsFromTheBeginning(): void
    {
        $this->insertRows(60);
        $agent = new OwnershipMailAgent('1');
        $this->startPass($agent);
        self::assertCount(35, $this->deliveryIds());
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE);
        $this->tick($agent);
        $this->tick($agent);
        self::assertCount(35, $this->deliveryIds());
        self::assertNull(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent));
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->tick($agent);
        self::assertCount(35, $this->deliveryIds());

        $beforeCursor = $this->insertDelivery(DeliveryStatus::SENT, 0);
        self::assertNotNull($agent->armCronRuleForTest('hilos_delivery_log_prune'));
        $this->tick($agent);
        self::assertCount(11, $this->deliveryIds());
        $this->tick($agent);
        self::assertSame([], $this->deliveryIds());
        self::assertNotContains($beforeCursor, $this->deliveryIds());
    }

    public function testRetryAfterPageSelectionKeepsTheRowPending(): void
    {
        $first = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $retried = $this->insertDelivery(DeliveryStatus::FAILED, 1);
        $retryOwed = true;
        SourceChangeBus::subscribe(new DeliveryRetentionMirror(
            static function (SourceChange $change) use ($first, $retried, &$retryOwed): void {
                if ($retryOwed && $change->sourceKey === HilosDbContext::notificationDeliveries
                    && $change->sourceId === (string)$first && $change->mutationType === TableMutationType::Delete) {
                    $retryOwed = false;
                    Hilos::$db->notificationDeliveries[$retried]->actions->resetForRetry();
                }
            },
        ));
        $this->startPass(new OwnershipMailAgent('1'));

        self::assertSame([['id' => (string)$retried, 'status' => DeliveryStatus::PENDING]], Database::sql(
            'SELECT id, status FROM hilos_notification_delivery ORDER BY id',
        )->rows());
    }

    public function testDeletionAnnouncesToTheImmediateMirror(): void
    {
        $this->insertDelivery(DeliveryStatus::SENT, 1);
        $announced = $this->announcements->count;
        $this->startPass(new OwnershipMailAgent('1'));

        self::assertSame([], $this->deliveryIds());
        self::assertSame($announced + 1, $this->announcements->count);
    }

    /** The harness uses the folded production grant, so fixture access cannot mask a library Remove. */
    public function testLibraryCannotRemoveDeliveryRows(): void
    {
        $id = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $this->assertRefusedWithoutChanges(static fn () => Hilos::$db->notificationDeliveries[$id]->actions->delete());

        self::assertSame([$id], $this->deliveryIds());
    }

    public function testFailedRemoveAbandonsThePassUntilAnotherRun(): void
    {
        $this->insertRows(30);
        $agent = new OwnershipMailAgent('1');
        $this->startPass($agent);
        $this->asClaimant($agent->getId(), static function () use ($agent): void {
            TruthSourceRegistry::register(
                HilosDbContext::notificationDeliveries, TruthSourceKeys::all(), $agent->getId(),
                TruthSourceOperations::of(TruthSourceOperation::Update),
            );
            $agent->onTick();
        });
        self::assertCount(5, $this->deliveryIds());
        self::assertNull(new ReflectionProperty(AbstractDeliveryChannelAgent::class, 'retentionCutoffMs')->getValue($agent));
        $this->tick($agent);
        self::assertCount(5, $this->deliveryIds());
        self::assertNotNull($agent->armCronRuleForTest('hilos_delivery_log_prune'));
        $this->tick($agent);
        self::assertSame([], $this->deliveryIds());
    }

    public function testProjectWithoutDeliveryFeatureRegistersNoRule(): void
    {
        DeliveryOwnershipHilos::initBrowser();
        $id = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $agent = new OwnershipMailAgent('1');
        $this->asClaimant($agent->getId(), $agent->onStart(...));
        self::assertNull($agent->armCronRuleForTest('hilos_delivery_log_prune'));
        $this->tick($agent);
        self::assertSame([$id], $this->deliveryIds());
    }

    public function testAbsentSettingsAccessorLeavesRowsUntouched(): void
    {
        Hilos::$setting = null;
        $id = $this->insertDelivery(DeliveryStatus::SENT, 1);
        $agent = new OwnershipMailAgent('1');
        $this->startPass($agent);
        self::assertSame([$id], $this->deliveryIds());
    }

    /** @param AbstractDeliveryChannelAgent $agent Channel/shard whose rule starts the pass */
    private function startPass(AbstractDeliveryChannelAgent $agent): void
    {
        $this->asClaimant($agent->getId(), function () use ($agent): void {
            $agent->onStart();
            self::assertNotNull($agent->armCronRuleForTest('hilos_delivery_log_prune'));
            $agent->onTick();
        });
    }

    /** @param AbstractDeliveryChannelAgent $agent Existing channel/shard instance to advance */
    private function tick(AbstractDeliveryChannelAgent $agent): void
    {
        $this->asClaimant($agent->getId(), $agent->onTick(...));
    }

    /** @param int $days Persisted retention age to insert before the accessor's first read */
    private function insertRetention(int $days): void
    {
        Database::sql('INSERT INTO hilos_setting (`key`, type, value) VALUES (?, ?, ?)', [
            DeliveryLogRetention::RETENTION_SETTING_KEY, SettingsCatalogConstants::TYPE_INTEGER, (string)$days,
        ]);
    }

    /**
     * @param int $count Number of old terminal rows
     * @param int $routingKey Saved key selecting a shard at width 2
     * @return list<int> Inserted delivery ids
     */
    private function insertRows(int $count, int $routingKey = 0): array
    {
        $ids = [];
        for ($row = 0; $row < $count; $row++) {
            $ids[] = $this->insertDelivery($row % 2 === 0 ? DeliveryStatus::SENT : DeliveryStatus::FAILED, 1, routingKey: $routingKey);
        }

        return $ids;
    }

    /**
     * @param string $status Delivery lifecycle status
     * @param int $createdAt Creation moment in epoch milliseconds
     * @param string $channel Saved channel identity
     * @param ?int $routingKey Saved sharding key, null for the legacy notification-id fallback
     * @return int Inserted primary key
     */
    private function insertDelivery(string $status, int $createdAt, string $channel = MailDeliveryChannel::NAME, ?int $routingKey = 0): int
    {
        Database::sql(
            'INSERT INTO hilos_notification_delivery (notification_id, channel, routing_key, status, created_at, updated_at) '
            . 'VALUES (11, ?, ?, ?, ?, ?)',
            [$channel, $routingKey, $status, $createdAt, $createdAt],
        );

        return (int)Database::sql('SELECT MAX(id) AS id FROM hilos_notification_delivery')->firstRow()['id'];
    }

    /** @return list<int> Remaining primary keys read independently of the ORM */
    private function deliveryIds(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql('SELECT id FROM hilos_notification_delivery ORDER BY id')->rows(),
        );
    }

    /** @param string $phase Node-local protected-mode phase to deliver through the regular mount */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new DeliveryRetentionRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));
    }
}

/** Only the delivery feature is needed to register the owner's retention schedule. */
abstract class DeliveryRetentionHilos extends DeliveryOwnershipHilos
{
    protected const array FEATURES = [HilosFeature::NOTIFICATION_DELIVERY];
    protected const string SETTINGS_CATALOG = DeliveryRetentionSettingsCatalog::class;
}

final class DeliveryRetentionSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Retention and enablement keys used by the real channel */
    public static function getCatalog(): array
    {
        return array_replace(DeliveryLogSettingsCatalog::getCatalog(), ChannelSettingsCatalog::getCatalog());
    }
}

/** The framework mount supplies the node's freeze alias; no project collections are required. */
final class DeliveryRetentionRtContext extends RtContext
{
    /** Leaves project state empty so only the mounted protected-mode singleton is read. */
    public function configure(): void
    {
    }
}

/** Synchronous mirror hook places a retry between selection and the next row's deletion. */
final class DeliveryRetentionMirror implements SourceMirrorSubscriberInterface
{
    /** @param Closure(SourceChange): void $onChange Test-local mutation to run at the announcement */
    public function __construct(private readonly Closure $onChange)
    {
    }

    /**
     * @param SourceChange $change Immediate source fact
     * @param SourceChangeProvenance $provenance Source path, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        ($this->onChange)($change);
    }
}
