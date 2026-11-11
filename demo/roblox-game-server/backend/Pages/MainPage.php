<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages;

use Demo\RobloxGameServer\Agents\RobloxGameServerAgent;
use Demo\RobloxGameServer\Constants\AgentType;
use Demo\RobloxGameServer\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the roblox-game-server demo.
 *
 * Declares no actions or signals: the home says who is looking and nothing more, and the name
 * it shows comes with the session identity every page already has. The game server this page
 * shows arrives with its own leaves.
 *
 * @property RobloxGameServerAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;
}
