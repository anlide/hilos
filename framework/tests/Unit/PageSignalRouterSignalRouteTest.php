<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for non-action signal routing to pages.
 */
final class PageSignalRouterSignalRouteTest extends TestCase
{
    public function testDispatchesNamedAgentSignalToConfiguredPage(): void
    {
        $factory = new PageSignalRouterTestPageFactory(new PageSignalRouterTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig(),
            [
                SignalTypeConstants::AGENT_SIGNAL => [
                    'moderation_result' => PageSignalRouterTestPage::PAGE,
                ],
            ],
        );
        $data = new AgentSignalData(new SignalData(['message' => 'ok']));

        $router->dispatchAgentSignal($data, 'agent', 'moderation_result');

        $page = $factory->getPage(PageSignalRouterTestPage::PAGE);
        $this->assertInstanceOf(PageSignalRouterTestPage::class, $page);
        $this->assertSame($data, $page->agentSignalData);
        $this->assertSame('moderation_result', $page->agentSignalName);
    }

    public function testDispatchesBinaryFrameBySignalTypeRoute(): void
    {
        $factory = new PageSignalRouterTestPageFactory(new PageSignalRouterTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig(),
            [
                SignalTypeConstants::FRAME_BINARY => PageSignalRouterTestPage::PAGE,
            ],
        );
        $data = new WebSocketFrameBinarySignalDTO('accept-key', 'payload');

        $router->dispatchFrameBinary($data, 'websocket', '');

        $page = $factory->getPage(PageSignalRouterTestPage::PAGE);
        $this->assertInstanceOf(PageSignalRouterTestPage::class, $page);
        $this->assertSame($data, $page->binarySignalData);
    }

    public function testDispatchesNamedCronSignalToConfiguredPage(): void
    {
        $factory = new PageSignalRouterTestPageFactory(new PageSignalRouterTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig(),
            [
                SignalTypeConstants::CRON => [
                    'cleanup_history' => PageSignalRouterTestPage::PAGE,
                ],
            ],
        );
        $data = new SignalData(['cronName' => 'cleanup_history']);

        $router->dispatchCron($data, 'daemon', 'cleanup_history');

        $page = $factory->getPage(PageSignalRouterTestPage::PAGE);
        $this->assertInstanceOf(PageSignalRouterTestPage::class, $page);
        $this->assertSame($data, $page->cronSignalData);
        $this->assertSame('cleanup_history', $page->cronSignalName);
    }

    public function testMissingSignalRouteDoesNotResolvePage(): void
    {
        $factory = new PageSignalRouterTestPageFactory(new PageSignalRouterTestAgent());
        $router = new PageSignalRouter(
            $factory,
            new ActionRouteConfig(),
        );

        $router->dispatchAgentSignal(
            new AgentSignalData(new SignalData()),
            'agent',
            'unknown',
        );

        $this->assertSame(0, $factory->createPageCount);
    }
}

final class PageSignalRouterTestPage extends AbstractPage
{
    public const string PAGE = 'main';

    public ?AgentSignalData $agentSignalData = null;
    public ?string $agentSignalName = null;
    public ?WebSocketFrameBinarySignalDTO $binarySignalData = null;
    public ?SignalData $cronSignalData = null;
    public ?string $cronSignalName = null;

    /**
     * Store the routed agent signal so the test can assert the dispatch target.
     *
     * @param AgentSignalData $data Wrapped signal payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $this->agentSignalData = $data;
        $this->agentSignalName = $name;
    }

    /**
     * Store the routed binary frame so the test can assert the dispatch target.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->binarySignalData = $data;
    }

    /**
     * Store the routed cron signal so the test can assert the dispatch target.
     *
     * @param SignalData $data Cron payload
     * @param string $source Signal source (unused)
     * @param string $name Cron job name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        assert($data instanceof SignalData);

        $this->cronSignalData = $data;
        $this->cronSignalName = $name;
    }
}

/**
 * Page factory fixture that exposes a single test page.
 *
 * @extends AbstractPageFactory<PageSignalRouterTestAgent>
 */
final class PageSignalRouterTestPageFactory extends AbstractPageFactory
{
    public int $createPageCount = 0;

    /**
     * Create the test page for the configured route.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        $this->createPageCount++;

        if ($pageName === PageSignalRouterTestPage::PAGE) {
            return new PageSignalRouterTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Report whether the test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PageSignalRouterTestPage::PAGE;
    }
}

final class PageSignalRouterTestAgent implements PageAgentInterface
{
    /**
     * Return the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'test-agent';
    }

    /**
     * Return the fixture signal source for page helpers.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
