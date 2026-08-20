<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages;

use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;

/**
 * MainPage - Main tasks page handler.
 *
 * Declares no actions or signals yet: the tasks CRUD contract arrives with the
 * first data-on-screen rewrite step.
 *
 * @property TasksAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::TASKS;
}
