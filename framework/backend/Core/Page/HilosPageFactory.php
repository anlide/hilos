<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\HilosPages\HilosAnalyticsPage;
use Hilos\Core\Page\HilosPages\HilosDashboardPage;
use Hilos\Core\Page\HilosPages\HilosGuardianPage;
use Hilos\Core\Page\HilosPages\HilosI18nPage;
use Hilos\Core\Page\HilosPages\HilosSettingsPage;

/**
 * HilosPageFactory - Factory for creating Hilos admin page instances
 *
 * Creates framework-level Hilos admin pages.
 * Project-level page factories should extend this class to inherit Hilos pages
 * and add their own pages via createPage()/hasPage() with parent delegation.
 *
 * @extends AbstractPageFactory<PageAgentInterface>
 */
class HilosPageFactory extends AbstractPageFactory
{
    /**
     * Create page instance by Hilos page name.
     *
     * @param string $pageName Page constant (e.g. HilosPageConstants::HILOS_DASHBOARD)
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When page cannot be created
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            HilosPageConstants::HILOS_DASHBOARD => new HilosDashboardPage($this->agent),
            HilosPageConstants::HILOS_SETTINGS => new HilosSettingsPage($this->agent),
            HilosPageConstants::HILOS_I18N => new HilosI18nPage($this->agent),
            HilosPageConstants::HILOS_GUARDIAN => new HilosGuardianPage($this->agent),
            HilosPageConstants::HILOS_ANALYTICS => new HilosAnalyticsPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
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
