<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\HilosMaintenanceStateSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Holds the Maintenance state frame's first answer, live changes and admission guard. */
final class HilosMaintenancePageStateTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-maintenance';

    private const string OTHER_KEY = 'ak-maintenance-other';

    private const int STARTED_AT = 1_700_000_000;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
        Hilos::initBrowser();
        Hilos::$browser = new IdentityTestBrowser(userId: 1, admin: true);
        AbstractHilosMaintenancePage::reset();
    }

    protected function tearDown(): void
    {
        AbstractHilosMaintenancePage::reset();
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testOpenStatePrecedesThePageResponse(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE, $signal?->signalName->getName());
        $this->assertSame([
            HilosMaintenanceStateSignalData::phase => StateProtectedModeRuntime::PHASE_INACTIVE,
            HilosMaintenanceStateSignalData::operation => null,
            HilosMaintenanceStateSignalData::manual => false,
            HilosMaintenanceStateSignalData::startedAt => null,
            HilosMaintenanceStateSignalData::enteredFromBrowser => false,
            HilosMaintenanceStateSignalData::passCount => 0,
            HilosMaintenanceStateSignalData::circleNamed => 0,
            HilosMaintenanceStateSignalData::circleAdmitted => 0,
        ], $this->payload($signal));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    public function testNoRuntimeRowSendsNoStateFrame(): void
    {
        Hilos::$rt = null;
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testManualAndOperationFramesCarryTheirOwnFacts(): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING);
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        $row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $row->initiatorAcceptKey = self::ACCEPT_KEY;
        $row->startedAt = self::STARTED_AT;
        $row->enteredFromBrowser = true;
        $row->passHashes = ['a', 'b'];
        $row->circleNamedCount = 3;
        $row->circleSessionTokenHashes = ['session-a'];

        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $manual = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue($manual[HilosMaintenanceStateSignalData::manual]);
        $this->assertSame('manual_maintenance', $manual[HilosMaintenanceStateSignalData::operation]);
        $this->assertSame('2023-11-14T22:13:20.000000Z', $manual[HilosMaintenanceStateSignalData::startedAt]);
        $this->assertTrue($manual[HilosMaintenanceStateSignalData::enteredFromBrowser]);
        $this->assertSame(2, $manual[HilosMaintenanceStateSignalData::passCount]);
        $this->assertSame(3, $manual[HilosMaintenanceStateSignalData::circleNamed]);
        $this->assertSame(1, $manual[HilosMaintenanceStateSignalData::circleAdmitted]);
        $this->drain();

        $row->operation = 'restore';
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE;
        $this->page()->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $row->initiatorAcceptKey = self::OTHER_KEY;
        $this->page()->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $operation = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertFalse($operation[HilosMaintenanceStateSignalData::manual]);
        $this->assertSame('restore', $operation[HilosMaintenanceStateSignalData::operation]);
    }

    public function testStaleTickPushesChangedCodesOnlyAndSkipsLockedOutSubscriber(): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $page->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->drain();

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertSame(2, $this->countStateSignals());

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        $row->initiatorAcceptKey = self::ACCEPT_KEY;
        $row->passHashes = ['first'];
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(self::ACCEPT_KEY, $signal?->data->targetAcceptKey);
        $this->assertSame(1, $this->payload($signal)[HilosMaintenanceStateSignalData::passCount]);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->passHashes[] = 'second';
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertSame(2, $this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::passCount]);
    }

    public function testViewModeViewerReceivesEveryNonPersonalField(): void
    {
        Hilos::$browser = new MaintenanceStateViewerBrowser();
        $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $wire = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertCount(8, $wire);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $wire[HilosMaintenanceStateSignalData::phase]);
        $this->assertNotContains(HiddenValue::mark(), $wire);
    }

    private function page(): MaintenanceStateTestPage
    {
        return new MaintenanceStateTestPage(new MaintenanceStateTestAgent());
    }

    private function mount(string $phase): StateProtectedModeRuntime
    {
        $row = StateProtectedModeRuntime::create();
        $row->phase = $phase;
        Hilos::$rt = new MaintenanceStateTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $row);

        return $row;
    }

    /** @return array<string, mixed> Wire payload of one state signal */
    private function payload(mixed $signal): array
    {
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

        return $signal->data->data->toArray();
    }

    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            continue;
        }
    }

    private function countStateSignals(): int
    {
        $count = 0;
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            $count++;
        }

        return $count;
    }
}

final class MaintenanceStateTestPage extends AbstractHilosMaintenancePage
{
}

final class MaintenanceStateTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class MaintenanceStateViewerBrowser extends IdentityTestBrowser
{
    public function __construct()
    {
        parent::__construct(null, false);
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return true;
    }
}

final class MaintenanceStateTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_index');
    }
}
