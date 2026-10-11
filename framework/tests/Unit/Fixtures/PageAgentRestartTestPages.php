<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;

/** Page topology shared by action units and the worker transport integration. */
final class PageAgentRestartTestPages
{
    public readonly HilosPageFactory $factory;
    public readonly PageSignalRouter $router;

    public function __construct()
    {
        $this->factory = new HilosPageFactory(new PageAgentRestartTestPageAgent(), PageAgentRestartTestHilos::class);
        $this->router = new PageSignalRouter(
            $this->factory,
            new ActionRouteConfig(PageAgentRestartTestHilos::getPageActionRoutes()),
            new SignalRouteConfig(PageAgentRestartTestHilos::getPageSignalRoutes()),
        );
    }
}

final class PageAgentRestartTestPage extends AbstractHilosDaemonAgentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'daemon_pages';
}

abstract class PageAgentRestartTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_DAEMON_AGENTS => PageAgentRestartTestPage::class];
}

final class PageAgentRestartTestPageAgent implements PageAgentInterface
{
    /** @return string Page agent identity */
    public function getId(): string
    {
        return 'daemon_pages:2';
    }

    /** @return SignalSourceInterface Server-owned page agent address */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'daemon_pages', '2');
    }
}
