<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Page\PageNotFoundException;

/**
 * AbstractPageFactory - Abstract factory for creating page instances
 *
 * Provides base implementation for page factories.
 * Child classes must implement createPage() to create specific page instances.
 */
abstract class AbstractPageFactory
{
    /** @var SignalRouter Signal router instance */
    protected SignalRouter $signalRouter;

    /** @var array<string, AbstractPage> Cached page instances */
    private array $pages = [];

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(SignalRouter $signalRouter)
    {
        $this->signalRouter = $signalRouter;
    }

    /**
     * Create page instance (factory method)
     *
     * Must be implemented in child classes to create specific page types.
     *
     * @param string $pageName Page name/identifier
     * @return AbstractPage Page instance
     * @throws PageNotFoundException If page cannot be created
     */
    abstract protected function createPage(string $pageName): AbstractPage;

    /**
     * Get page instance by name
     *
     * Returns cached instance if available, otherwise creates new one.
     *
     * @param string $pageName Page name/identifier
     * @return AbstractPage Page instance
     * @throws PageNotFoundException If page cannot be created
     */
    public function getPage(string $pageName): AbstractPage
    {
        if (!isset($this->pages[$pageName])) {
            $this->pages[$pageName] = $this->createPage($pageName);
        }

        return $this->pages[$pageName];
    }

    /**
     * Check if page exists
     *
     * @param string $pageName Page name/identifier
     * @return bool True if page can be created
     */
    abstract public function hasPage(string $pageName): bool;
}
