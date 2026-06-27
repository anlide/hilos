<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default AbstractPage::onSubscribe page_response emission.
 */
final class AbstractPagePageResponseEmitTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = null;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    public function testSubscribeEmitsPageResponseForAPayloadBearingPage(): void
    {
        $page = new AbstractPagePageResponseEmitTestPayloadPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => AbstractPagePageResponseEmitTestPayloadPage::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::entities => ['currentUser' => ['id' => 7, 'name' => 'Ada']],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    public function testSubscribeEmitsNothingForADefaultPage(): void
    {
        $page = new AbstractPagePageResponseEmitTestDefaultPage(new AbstractPagePageResponseEmitTestAgent());

        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

/**
 * Test page contributing an entity-only page payload on subscription.
 */
final class AbstractPagePageResponseEmitTestPayloadPage extends AbstractPage
{
    public const string PAGE = 'probe_payload';

    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(entities: ['currentUser' => ['id' => 7, 'name' => 'Ada']]);
    }
}

/**
 * Test page that keeps the default empty page payload.
 */
final class AbstractPagePageResponseEmitTestDefaultPage extends AbstractPage
{
    public const string PAGE = 'probe_default';
}

/**
 * Minimal page agent providing a signal source for sendToUser.
 */
final class AbstractPagePageResponseEmitTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
