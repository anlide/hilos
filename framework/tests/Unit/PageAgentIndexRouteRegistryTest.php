<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Topology\PageAgentIndexRouteRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests PageAgentIndexRouteRegistry aggregation of SUBSCRIPTION_AGENT_INDEX across pages.
 */
final class PageAgentIndexRouteRegistryTest extends TestCase
{
    public function testParamSourcePageYieldsRouteCarryingItsParamAndFallback(): void
    {
        $routes = PageAgentIndexRouteRegistry::routes([
            IndexRouteParamPage::PAGE => IndexRouteParamPage::class,
        ]);

        $this->assertSame([IndexRouteParamPage::PAGE], array_keys($routes));
        $route = $routes[IndexRouteParamPage::PAGE];
        $this->assertInstanceOf(PageAgentIndexRoute::class, $route);
        $this->assertSame(PageAgentIndexSource::PARAM, $route->source);
        $this->assertSame('chatId', $route->param);
        $this->assertSame('index_route_agent', $route->fallbackAgentType);
    }

    public function testSessionUserSourcePageYieldsRouteWithoutAParam(): void
    {
        $routes = PageAgentIndexRouteRegistry::routes([
            IndexRouteSessionUserPage::PAGE => IndexRouteSessionUserPage::class,
        ]);

        $route = $routes[IndexRouteSessionUserPage::PAGE];
        $this->assertSame(PageAgentIndexSource::SESSION_USER, $route->source);
        $this->assertNull($route->param);
        $this->assertSame('index_route_agent', $route->fallbackAgentType);
    }

    public function testPageDeclaringNothingIsAbsentFromTheRegistry(): void
    {
        $this->assertSame([], PageAgentIndexRouteRegistry::routes([
            IndexRoutePlainPage::PAGE => IndexRoutePlainPage::class,
        ]));
    }

    /**
     * Every malformed shape is dropped in silence; naming what is wrong belongs to the validator.
     */
    public function testMalformedDeclarationsAreSkipped(): void
    {
        $this->assertSame([], PageAgentIndexRouteRegistry::routes([
            IndexRouteNoSourcePage::PAGE => IndexRouteNoSourcePage::class,
            IndexRouteParamWithoutParamPage::PAGE => IndexRouteParamWithoutParamPage::class,
            IndexRouteNoFallbackPage::PAGE => IndexRouteNoFallbackPage::class,
        ]));
    }

    public function testNonPageRegistryEntriesAreSkipped(): void
    {
        $this->assertSame([], PageAgentIndexRouteRegistry::routes([
            'not_a_page' => PageAgentIndexRoute::class,
            0 => IndexRouteParamPage::class,
            IndexRouteParamPage::PAGE => 42,
        ]));
    }

    public function testEveryDeclaringPageOfAMixedRegistryIsReturned(): void
    {
        $routes = PageAgentIndexRouteRegistry::routes([
            IndexRoutePlainPage::PAGE => IndexRoutePlainPage::class,
            IndexRouteParamPage::PAGE => IndexRouteParamPage::class,
            IndexRouteSessionUserPage::PAGE => IndexRouteSessionUserPage::class,
        ]);

        $this->assertSame(
            [IndexRouteParamPage::PAGE, IndexRouteSessionUserPage::PAGE],
            array_keys($routes),
        );
    }
}

final class IndexRoutePlainPage extends AbstractPage
{
    public const string PAGE = 'index_route_plain_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';
}

final class IndexRouteParamPage extends AbstractPage
{
    public const string PAGE = 'index_route_param_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'chatId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'index_route_agent',
    ];
}

final class IndexRouteSessionUserPage extends AbstractPage
{
    public const string PAGE = 'index_route_session_user_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'index_route_agent',
    ];
}

final class IndexRouteNoSourcePage extends AbstractPage
{
    public const string PAGE = 'index_route_no_source_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::PARAM => 'chatId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'index_route_agent',
    ];
}

final class IndexRouteParamWithoutParamPage extends AbstractPage
{
    public const string PAGE = 'index_route_param_without_param_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => '',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'index_route_agent',
    ];
}

final class IndexRouteNoFallbackPage extends AbstractPage
{
    public const string PAGE = 'index_route_no_fallback_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'index_route_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER,
    ];
}
