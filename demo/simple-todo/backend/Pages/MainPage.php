<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Pages;

use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Constants\AgentType;
use Demo\SimpleTodo\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;

/**
 * MainPage - Main todo page handler.
 *
 * Declares no actions or signals yet: the todo CRUD contract arrives with the
 * first data-on-screen rewrite step.
 *
 * @property TodoAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::TODO;
}
