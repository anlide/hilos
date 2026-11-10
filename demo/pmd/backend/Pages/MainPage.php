<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages;

use Demo\Pmd\Agents\PmdAgent;
use Demo\Pmd\Constants\AgentType;
use Demo\Pmd\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * PMD home subscription, with no project actions or payload.
 *
 * @property PmdAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
}
