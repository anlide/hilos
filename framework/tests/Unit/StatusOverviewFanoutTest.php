<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Constants\SignalConstants;
use Hilos\Hilos;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\HilosStatusMaintenance;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tests\Unit\Status\StatusPageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Final wire from the production PageResender and Status builder, including guards and recovery. */
final class StatusOverviewFanoutTest extends StatusPageTestCase
{
    /** @return iterable<string, array{string, string, TableMutationType}> All relevant source mutations */
    public static function facts(): iterable
    {
        foreach ([SourceChange::KIND_DB => [HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::settings,
            HilosDbContext::statusSubscribers, HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents],
            SourceChange::KIND_RT => [HilosStatusComponent::RT_COLLECTION, ProtectedModeRuntime::RT_ITEM,
                HilosStatusMaintenance::RT_COLLECTION]] as $kind => $keys) {
            foreach ($keys as $source) {
                foreach (TableMutationType::cases() as $mutation) {
                    yield $kind . '-' . $source . '-' . $mutation->value => [$kind, $source, $mutation];
                }
            }
        }
    }

    /**
     * @param string $kind DB or RT
     * @param string $source Source key
     * @param TableMutationType $mutation Create, update, remove or clear
     */
    #[DataProvider('facts')]
    public function testEveryOwnSourceMutationDeliversACompleteCurrentAnswer(string $kind, string $source, TableMutationType $mutation): void
    {
        $this->subscribe();
        $this->incident(7, 'zeta', suppressedAt: 1700000000040);
        $this->browser->record(new SourceChange($kind, $source, '7', $mutation));
        $this->flush();
        $body = $this->whole();
        self::assertTrue($body['healthKnown']);
        self::assertSame(7, $body['incidents'][0]['incidentId']);
        self::assertSame(1700000000040, $body['incidents'][0]['suppressedAt']);
        self::assertSame(1700000300000, $body['incidents'][0]['publishAt']);
        self::assertSame(['operationalCount' => 1, 'totalCount' => 2], $body['components']);
        $this->flush();
        self::assertSame([], $this->drain());
    }

    public function testManyDbRtFactsAndStalenessBecomeOneWholeAnswerInAFlush(): void
    {
        $this->subscribe();
        $this->incident(7, 'zeta', 1700000000010);
        $this->update(9, 7, 1700000000020);
        $this->factsForFlush();
        $this->browser->recordSourceStaleness(HilosStatusComponent::RT_COLLECTION, ['zeta']);
        $this->browser->recordDatabaseBack();
        $this->flush();
        $body = $this->whole();
        self::assertSame('Team <text>', $body['incidents'][0]['updates'][0]['text']);
        self::assertSame(300, $body['publishDelaySeconds']);
    }

    public function testInitialAnswerForAnotherViewerDoesNotConsumeAPendingFact(): void
    {
        $this->subscribe();
        $this->incident(7, 'zeta');
        $this->factsForFlush();
        $this->initial('second');
        self::assertSame('second', $this->drain()[0]->data->targetAcceptKey);
        $this->flush();
        $frames = $this->drain();
        self::assertCount(2, $frames);
        self::assertSame(['overview', 'second'], array_map(static fn ($frame) => $frame->data->targetAcceptKey, $frames));
        foreach ($frames as $frame) {
            self::assertSame(7, $frame->data->data->payload->data['status']['incidents'][0]['incidentId']);
        }
    }

    public function testForeignSourcesAndAnExplicitOtherSettingDoNotRedeliver(): void
    {
        $this->subscribe();
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::settings, '8', ['key' => 'other.setting']));
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::users, '1', []));
        $this->browser->record(SourceChange::rtUpdated(HilosDbContext::statusIncidents, '1', []));
        $this->browser->recordSourceStaleness('other-runtime', ['one']);
        $this->flush();
        self::assertSame([], $this->drain());
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::settings, '8', ['key' => StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]));
        $this->flush();
        self::assertSame([], $this->whole()['incidents']);
    }

    public function testFreshStaleFreshCountsRecomputeWithoutFakingHealthyValues(): void
    {
        $this->subscribe();
        RtStaleness::mark(HilosStatusComponent::RT_COLLECTION, ['alpha'], 10.0);
        $this->browser->recordSourceStaleness(HilosStatusComponent::RT_COLLECTION, ['alpha']);
        $this->flush();
        $body = $this->whole();
        self::assertFalse($body['healthKnown']);
        self::assertSame(['operationalCount' => 1, 'totalCount' => 2], $body['components']);
        RtStaleness::clear(HilosStatusComponent::RT_COLLECTION, ['alpha']);
        $this->browser->recordSourceStaleness(HilosStatusComponent::RT_COLLECTION, ['alpha']);
        $this->flush();
        $body = $this->whole();
        self::assertTrue($body['healthKnown']);
        self::assertSame(['operationalCount' => 2, 'totalCount' => 2], $body['components']);
    }

    public function testFreezeFreshnessNotificationsReadTheCurrentNodeLocalSingleton(): void
    {
        $this->subscribe('secret');
        $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING, 1700000000, ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE);
        $this->browser->recordSourceStaleness(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID]);
        $this->flush();
        $body = $this->whole('secret');
        self::assertSame(['phase' => 'verifying', 'operation' => 'manual_maintenance',
            'startedAt' => 1700000000000, 'announcement' => null], $body['maintenance']);
        self::assertSame(0, $body['components']['operationalCount']);
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->staleSince(), 'Node-local singleton has no replicated stale copy');
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->browser->recordSourceStaleness(ProtectedModeRuntime::RT_ITEM, [ProtectedModeRuntime::ID]);
        $this->flush();
        $body = $this->whole('secret');
        self::assertNull($body['maintenance']);
        self::assertSame(2, $body['components']['operationalCount']);
    }

    public function testLiveDelaySettingDoesNotRecomputeAnExistingIncidentDeadline(): void
    {
        $this->subscribe();
        $this->incident(7, 'zeta');
        $setting = new EntitySetting();
        $setting->id = 1;
        $setting->key = StatusSettingsCatalog::PUBLISH_DELAY_SECONDS;
        $setting->type = 'int';
        $setting->value = '15';
        $this->database->addSetting($setting);
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::settings, '1', ['value' => '15']));
        $this->flush();
        $body = $this->whole();
        self::assertSame(15, $body['publishDelaySeconds']);
        self::assertSame(1700000300000, $body['incidents'][0]['publishAt']);
    }

    public function testUnknownDbFactsRecoverToKnownEmptyOnADatabaseBackFact(): void
    {
        $this->subscribe();
        $this->database->statusIncidents->unavailable = true;
        $this->factsForFlush();
        $this->flush();
        self::assertNull($this->whole()['incidents']);
        $this->database->statusIncidents->unavailable = false;
        Hilos::$sr->markPageDeliveryFailure('overview');
        $this->browser->recordDatabaseBack();
        $this->flush();
        self::assertSame([], $this->whole()['incidents']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    public function testARefusedDebtRemainsUntilRightsAllowTheNextFact(): void
    {
        $this->subscribe();
        Hilos::$sr->markPageDeliveryFailure('overview');
        $this->browser->admin = false;
        $this->factsForFlush();
        $this->flush();
        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        $this->browser->viewer = true;
        $this->incident(7, 'zeta', 1700000000010);
        $this->update(9, 7, 1700000000030);
        $this->factsForFlush();
        $this->flush();
        self::assertSame(HiddenValue::mark(), $this->whole()['incidents'][0]['updates'][0]['text']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    public function testFreezeGuardRefusesAndThawLetsTheSameSubscriptionReceiveAgain(): void
    {
        $this->subscribe();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, 1700000000, 'restore');
        $this->factsForFlush();
        $this->flush();
        self::assertSame([], $this->drain());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->factsForFlush();
        $this->flush();
        self::assertTrue($this->whole()['healthKnown']);
    }

    public function testACompleteFailureOwesThePageUntilTheNextOwnFactAfterRepair(): void
    {
        $this->subscribe();
        Hilos::$setting = new SettingsAccessor(StatusEmptySettingsCatalog::class);
        $this->factsForFlush();
        $this->flush();
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]->signalName->getName());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        $this->factsForFlush();
        $this->flush();
        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        $this->factsForFlush();
        $this->flush();
        self::assertTrue($this->whole()['healthKnown']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    public function testAnEndedIncidentDisappearsFromTheNextCompleteWire(): void
    {
        $this->subscribe();
        $incident = $this->incident(7, 'zeta');
        $this->factsForFlush();
        $this->flush();
        self::assertSame(7, $this->whole()['incidents'][0]['incidentId']);
        $incident->active_component_key = null;
        $this->browser->record(SourceChange::dbDeleted(HilosDbContext::statusIncidents, '7', []));
        $this->flush();
        self::assertSame([], $this->whole()['incidents']);
        $this->browser->record(SourceChange::dbCleared(HilosDbContext::statusIncidents));
        $this->flush();
        self::assertSame([], $this->whole()['incidents']);
    }

    public function testRollbackDoesNotAnnounceAFact(): void
    {
        $this->subscribe();
        Database::transactionStart();
        Database::afterCommit(fn () => $this->factsForFlush());
        Database::transactionRollback();
        $this->flush();
        self::assertSame([], $this->drain());
    }

    /** @return iterable<string, array{string}> Replacement shapes during access lookup */
    public static function replacements(): iterable
    {
        yield 'leave' => ['leave'];
        yield 'new page' => ['other'];
        yield 'new subscription' => ['replace'];
        yield 'changed params' => ['params'];
        yield 'staleness replacement' => ['staleness'];
    }

    /** @param string $replacement Subscription change during the guard */
    #[DataProvider('replacements')]
    public function testChangedSubscriptionsCannotReceiveAnOldGuardVerdict(string $replacement): void
    {
        $this->subscribe();
        $this->browser->duringIdentity = static function () use ($replacement): void {
            if ($replacement === 'leave') {
                Hilos::$sr->unsubscribeFromPage(AbstractHilosStatusPage::PAGE,
                    new WebSocketPageUnsubscribeSignalDTO('overview', AbstractHilosStatusPage::PAGE));
            } elseif ($replacement === 'params') {
                Hilos::$sr->updatePageSubscription(AbstractHilosStatusPage::PAGE,
                    new WebSocketPageUpdateSubscriptionSignalDTO('overview', AbstractHilosStatusPage::PAGE, ['new' => 'yes']));
            } else {
                $page = $replacement === 'other' ? HilosPageConstants::HILOS_STATUS_COMPONENTS : AbstractHilosStatusPage::PAGE;
                Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('overview', $page));
            }
        };
        if ($replacement === 'staleness') {
            $this->browser->recordSourceStaleness(HilosStatusComponent::RT_COLLECTION, ['alpha']);
        } else {
            $this->factsForFlush();
        }
        $this->flush();
        self::assertSame([], $this->drain());
    }

    private function factsForFlush(): void
    {
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::statusIncidents, '7', []));
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::statusIncidentUpdates, '9', []));
        $this->browser->record(SourceChange::rtUpdated(HilosStatusComponent::RT_COLLECTION, 'zeta', []));
    }

    /**
     * @param string $acceptKey Expected recipient
     * @return array<string, mixed> Complete current overview body
     */
    private function whole(string $acceptKey = 'overview'): array
    {
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->data->targetAcceptKey);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        $reply = $frames[0]->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame(AbstractHilosStatusPage::PAGE, $reply->pageKey);
        self::assertSame('Status', $reply->payload->data['pageLabel']);
        self::assertSame('Status', $reply->payload->data['pageHeadOwner']['label']);
        self::assertCount(4, $reply->payload->data['pageTabs']);
        self::assertSame([], $reply->payload->windows);
        self::assertSame($reply->toArray(), PageResponseSignalData::fromArray($reply->toArray())->toArray());
        return $reply->payload->data['status'];
    }
}
