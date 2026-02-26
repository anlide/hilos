<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\UnknownActionPayloadDTO;

/**
 * AbstractPageFactory - Abstract factory for creating page instances.
 *
 * Provides base implementation for page factories.
 * Child classes must implement createPage() to create specific page instances.
 * Signal router is available globally via Hilos::$sr.
 *
 * @template TAgent of PageAgentInterface
 */
abstract class AbstractPageFactory
{
    /** @var TAgent Agent instance for pages */
    protected PageAgentInterface $agent;

    /** @var array<string, AbstractPage> Cached page instances */
    private array $pages = [];

    /**
     * Constructor
     *
     * @param PageAgentInterface $agent Agent instance
     */
    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
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

    /**
     * Create ActionPayloadDTO from action and data
     *
     * Override in child class to create specific DTOs for known actions.
     * Default implementation returns UnknownActionPayloadDTO.
     *
     * @param string $action Action name
     * @param array $data Payload data
     * @return ActionPayloadDTO Action payload DTO
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        return new UnknownActionPayloadDTO($action, $data);
    }
}
