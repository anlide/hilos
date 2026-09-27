<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfileDataPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The data section and profile carry the person's own copy and join its live group. */
final class ProfileDataPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'profile-data-test';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'profile-data-test-agent');
        TruthSourceRegistry::register(HilosDbContext::dataExports, TruthSourceKeys::all(), 'profile-data-test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testBothPagesCarryAbsenceThenOwnCopyAndJoinTheGroup(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Data copy owner')->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);
        $foreignUserId = (int)Hilos::$db->users->actions->createWithName('Foreign copy owner')->id;
        Hilos::$db->dataExports->actions->order($foreignUserId, '2026-09-27 10:00:00');

        foreach ([ProfileDataPage::class, ProfilePage::class] as $pageClass) {
            $payload = $this->subscribe($pageClass);
            self::assertArrayHasKey(DataExportStateProjector::SECTION, $payload[PagePayload::data]);
            self::assertNull($payload[PagePayload::data][DataExportStateProjector::SECTION]);
            self::assertSame(
                DataExportGroup::forUser($userId),
                Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, DataExportGroup::NAME),
            );
        }

        $export = Hilos::$db->dataExports->actions->order($userId, '2026-09-27 10:00:00');
        $export->actions->finishReady('own.zip', 123, '2026-09-27 10:01:00', '2026-10-04 10:01:00');
        foreach ([ProfileDataPage::class, ProfilePage::class] as $pageClass) {
            self::assertSame(
                DataExportStateProjector::nodeFor($export),
                $this->subscribe($pageClass)[PagePayload::data][DataExportStateProjector::SECTION],
            );
        }
    }

    public function testAnonymousSubscriptionContributesNoPersonalSection(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        foreach ([ProfileDataPage::class, ProfilePage::class] as $pageClass) {
            self::assertArrayNotHasKey(
                DataExportStateProjector::SECTION,
                $this->subscribe($pageClass)[PagePayload::data] ?? [],
            );
            self::assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, DataExportGroup::NAME));
        }
    }

    /**
     * @param class-string<ProfileDataPage|ProfilePage> $pageClass Page under test
     * @return array<string, mixed> The page's subscription payload
     */
    private function subscribe(string $pageClass): array
    {
        Hilos::$sr = new SignalRouter();
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function () use ($pageClass): void {
                new $pageClass(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );
        $payload = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $response = $signal->data->data->toArray();
            self::assertSame($pageClass::PAGE, $response[PageResponseSignalData::page]);

            $payload = $response[PageResponseSignalData::payload] ?? [];
        }
        self::assertNotNull($payload, 'The page sent no response');

        return $payload;
    }
}
