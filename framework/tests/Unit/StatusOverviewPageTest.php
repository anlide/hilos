<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\DTO\HilosStatusIncidentActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusAddUpdateActionDTO;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tests\Unit\Status\StatusPageTestCase;
use Hilos\Tests\Unit\Status\StatusPage;
use ReflectionProperty;

/** First page responses contain the complete screen and the catalog-owned head. */
final class StatusOverviewPageTest extends StatusPageTestCase
{
    public function testFirstOverviewIsOneWholeResponseWithNoCompanionSignal(): void
    {
        $this->incident(7, 'zeta', 1700000000010);
        $this->update(9, 7, 1700000000030);
        $this->initial();
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        $reply = $frames[0]->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame(AbstractHilosStatusPage::PAGE, $reply->pageKey);
        self::assertSame(7, $reply->payload->data['status']['incidents'][0]['incidentId']);
        self::assertSame('Team <text>', $reply->payload->data['status']['incidents'][0]['updates'][0]['text']);
        self::assertSame(1700000000030, $reply->payload->data['status']['incidents'][0]['updates'][0]['at']);
        self::assertTrue($reply->payload->data['status']['healthKnown']);
        self::assertSame(['operationalCount' => 1, 'totalCount' => 2], $reply->payload->data['status']['components']);
        self::assertSame(300, $reply->payload->data['status']['publishDelaySeconds']);
        self::assertNull($reply->payload->data['status']['maintenance']);
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 0], $reply->payload->data['status']['subscribers']);
        self::assertSame('Status', $reply->payload->data['pageHeadOwner']['label']);
        self::assertSame('bi-activity', $reply->payload->data['pageHeadOwner']['icon']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Status'],
        ], $reply->payload->data['pageBreadcrumb']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_STATUS_WORKS, 'label' => 'Scheduled maintenance'],
            ['page' => HilosPageConstants::HILOS_STATUS_COMPONENTS, 'label' => 'Components'],
            ['page' => HilosPageConstants::HILOS_STATUS_SUBSCRIBERS, 'label' => 'Subscribers'],
        ], $reply->payload->data['pageTabs']);
        self::assertSame([], $reply->payload->windows);
        self::assertSame($reply->toArray(), PageResponseSignalData::fromArray($reply->toArray())->toArray());
    }

    public function testFirstComponentsCarriesTheWindowAndSameOwner(): void
    {
        $this->initial('secret', AbstractHilosStatusComponentsPage::PAGE);
        $frames = $this->drain();
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        $reply = $frames[0]->data->data;
        self::assertSame(AbstractHilosStatusComponentsPage::PAGE, $reply->pageKey);
        self::assertArrayNotHasKey('status', $reply->payload->data);
        self::assertSame(AbstractHilosStatusPage::PAGE, $reply->payload->data['pageHeadOwner']['page']);
        $window = $reply->payload->windows[HilosStatusComponentsTable::TABLE];
        self::assertSame(['zeta', 'alpha'], array_column($window['rows'], 'rowKey'));
        self::assertSame('operational', $window['rows'][0]['slots']['component']['state']);
        foreach (array_slice($frames, 1) as $frame) {
            self::assertSame(SignalTypeConstants::TABLE_FACET_COUNTS, $frame->signalName->getName());
        }
        self::assertSame([], AbstractHilosStatusComponentsPage::ACTIONS);
    }

    public function testViewerGetsColumnMaskingOnTheInitialWholeAnswer(): void
    {
        $this->browser->admin = false;
        $this->browser->viewer = true;
        $this->incident(7, 'zeta', 1700000000010);
        $this->update(9, 7, 1700000000030);
        $this->initial();
        $body = $this->drain()[0]->data->data->toArray()['payload']['data']['status'];
        self::assertSame(HiddenValue::mark(), $body['incidents'][0]['updates'][0]['text']);
        self::assertSame(7, $body['incidents'][0]['incidentId']);
        self::assertSame(300, $body['publishDelaySeconds']);
    }

    public function testSilencedFreezeNeverReadsIncidentFacts(): void
    {
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, 1700000000, 'restore');
        $this->database->statusIncidents->unavailable = true;
        $this->initial('secret');
        $body = $this->drain()[0]->data->data->payload->data['status'];
        self::assertSame(0, $this->database->statusIncidents->reads);
        self::assertNull($body['incidents']);
        self::assertNull($body['publishDelaySeconds']);
        self::assertFalse($body['healthKnown']);
        self::assertSame(1700000000000, $body['maintenance']['startedAt']);
    }

    public function testCatalogOmitsAnUnservedComponentsTab(): void
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusOverviewOnlyHilos::class);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Overview'],
        ], PageCatalogResolver::head(HilosPageConstants::HILOS_STATUS, new PageRouteParams([]))->pageTabs);
    }

    public function testActionReadersKeepStrictPositiveTargetsAndUnmodifiedText(): void
    {
        self::assertSame(['incidentId' => 17], HilosStatusIncidentActionDTO::fromArray(['incidentId' => 17])->toArray());
        self::assertSame(['incidentId' => 17, 'text' => '  <b>Я</b>  '],
            HilosStatusAddUpdateActionDTO::fromArray(['data' => ['incidentId' => 17, 'text' => '  <b>Я</b>  ']])->toArray());
        foreach ([[], ['incidentId' => 0], ['incidentId' => -1], ['incidentId' => '17'], ['incidentId' => true], ['data' => null]] as $bad) {
            try {
                HilosStatusIncidentActionDTO::fromArray($bad);
                self::fail('Malformed target passed');
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
        foreach ([['incidentId' => 17], ['incidentId' => 17, 'text' => null], ['incidentId' => 17, 'text' => 3]] as $bad) {
            try {
                HilosStatusAddUpdateActionDTO::fromArray($bad);
                self::fail('Malformed text passed');
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
    }
}

abstract class StatusOverviewOnlyHilos extends Hilos
{
    public const array PAGES = [StatusPage::PAGE => StatusPage::class];
}
