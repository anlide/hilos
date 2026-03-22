<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\Exception\PageNotFoundException;

/**
 * HilosPageFactory - Factory for creating Hilos admin page instances.
 *
 * Hilos pages are abstract; projects must implement concrete classes.
 * For HILOS_* page names, createPage() throws - child factory (e.g. ChatPageFactory)
 * must handle these and return concrete implementations.
 *
 * @extends AbstractPageFactory<PageAgentInterface>
 */
class HilosPageFactory extends AbstractPageFactory
{
    /**
     * All Hilos admin page ids (dashboard, i18n subtree, guardian, etc.).
     *
     * @return list<string>
     */
    private static function hilosPageIds(): array
    {
        return [
            HilosPageConstants::HILOS_DASHBOARD,
            HilosPageConstants::HILOS_SETTINGS,
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_I18N_LANGUAGES,
            HilosPageConstants::HILOS_I18N_COUNTRIES,
            HilosPageConstants::HILOS_I18N_ENTITIES,
            HilosPageConstants::HILOS_I18N_UI_PAGES,
            HilosPageConstants::HILOS_I18N_GROUPS,
            HilosPageConstants::HILOS_I18N_ACTIONS,
            HilosPageConstants::HILOS_I18N_EMAILS,
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_I18N_UI_PAGE,
            HilosPageConstants::HILOS_I18N_GROUP,
            HilosPageConstants::HILOS_I18N_ACTION,
            HilosPageConstants::HILOS_I18N_TRANSLATE_ENTITY,
            HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE,
            HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM,
            HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP,
            HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM,
            HilosPageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR,
            HilosPageConstants::HILOS_I18N_TRANSLATE_EMAIL,
            HilosPageConstants::HILOS_GUARDIAN,
            HilosPageConstants::HILOS_GUARDIAN_AGENT,
            HilosPageConstants::HILOS_ANALYTICS,
            HilosPageConstants::HILOS_BACKUP,
            HilosPageConstants::HILOS_DAEMON,
            HilosPageConstants::HILOS_DAEMON_WORKERS,
            HilosPageConstants::HILOS_DAEMON_AGENTS,
            HilosPageConstants::HILOS_DAEMON_CRON,
            HilosPageConstants::HILOS_DAEMON_WEBSOCKETS,
            HilosPageConstants::HILOS_DAEMON_HTTP_SERVER,
            HilosPageConstants::HILOS_LOGS,
            HilosPageConstants::HILOS_LOGS_KEYS,
            HilosPageConstants::HILOS_LOGS_WORKERS,
            HilosPageConstants::HILOS_LOGS_ROTATIONS,
            HilosPageConstants::HILOS_LOGS_VIEW,
            HilosPageConstants::HILOS_OPERATIONS,
            HilosPageConstants::HILOS_USERS,
            HilosPageConstants::HILOS_USER,
            HilosPageConstants::HILOS_ROLES,
            HilosPageConstants::HILOS_MCP_SKILLS,
            HilosPageConstants::HILOS_MCP_SKILLS_MCP,
            HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS,
            HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW,
            HilosPageConstants::HILOS_SIL,
            HilosPageConstants::HILOS_SIL_REQUESTS,
            HilosPageConstants::HILOS_SIL_USER_HISTORY,
        ];
    }

    /**
     * Create page instance by Hilos page name.
     *
     * For HILOS_* constants, throws - implement in project's page factory.
     *
     * @param string $pageName Page constant (e.g. HilosPageConstants::HILOS_DASHBOARD)
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When page cannot be created (HILOS_* or unknown)
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if (in_array($pageName, self::hilosPageIds(), true)) {
            throw new PageNotFoundException(
                "Hilos page '{$pageName}' requires implementation in project. "
                . "Create concrete page extending AbstractHilos*Page in your project (e.g. Demo\\Chat\\Pages\\Hilos\\SettingsPage)."
            );
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Check if Hilos page name is supported.
     *
     * @param string $pageName Page name constant
     * @return bool True if page exists
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, self::hilosPageIds(), true);
    }
}
