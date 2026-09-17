<?php

declare(strict_types=1);

namespace Demo\Polls\Pages;

use Demo\Polls\Agents\PollsAgent;
use Demo\Polls\Constants\AgentType;
use Demo\Polls\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - Main polls page handler.
 *
 * Declares no actions or signals yet: the poll CRUD contract arrives with the
 * first data-on-screen rewrite step.
 *
 * @property PollsAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
}
