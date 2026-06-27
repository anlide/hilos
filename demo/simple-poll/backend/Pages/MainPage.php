<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\AgentType;
use Demo\SimplePoll\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;

/**
 * MainPage - Main poll page handler.
 *
 * Declares no actions or signals yet: the poll CRUD contract arrives with the
 * first data-on-screen rewrite step.
 *
 * @property PollAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLL;
}
