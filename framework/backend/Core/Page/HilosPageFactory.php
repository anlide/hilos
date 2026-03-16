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
        if (in_array($pageName, [
            HilosPageConstants::HILOS_DASHBOARD,
            HilosPageConstants::HILOS_SETTINGS,
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_GUARDIAN,
            HilosPageConstants::HILOS_ANALYTICS,
        ], true)) {
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
        return in_array($pageName, [
            HilosPageConstants::HILOS_DASHBOARD,
            HilosPageConstants::HILOS_SETTINGS,
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_GUARDIAN,
            HilosPageConstants::HILOS_ANALYTICS,
        ], true);
    }
}
