<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\UnknownActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalPayloadHydrator;
use Hilos\Hilos;
use Throwable;

/**
 * HilosPageFactory - Factory for creating page instances from project topology.
 *
 * Resolves pages and action payload DTOs from the active project Hilos facade.
 * Known Hilos admin page ids remain visible through hasPage() until the project
 * registers concrete implementations in Hilos::PAGES.
 */
class HilosPageFactory extends AbstractPageFactory
{
    /**
     * All Hilos admin page ids (dashboard, i18n subtree, guardian, etc.) as a
     * lookup set keyed by page id, so membership checks stay O(1) and the list
     * is built once at class load instead of on every hasPage() call.
     *
     * @var array<string, true>
     */
    private const array HILOS_PAGE_IDS = [
        HilosPageConstants::HILOS_DASHBOARD => true,
        HilosPageConstants::HILOS_PROFILE => true,
        HilosPageConstants::HILOS_SETTINGS => true,
        HilosPageConstants::HILOS_I18N => true,
        HilosPageConstants::HILOS_I18N_LANGUAGES => true,
        HilosPageConstants::HILOS_I18N_COUNTRIES => true,
        HilosPageConstants::HILOS_I18N_ENTITIES => true,
        HilosPageConstants::HILOS_I18N_UI_PAGES => true,
        HilosPageConstants::HILOS_I18N_GROUPS => true,
        HilosPageConstants::HILOS_I18N_ACTIONS => true,
        HilosPageConstants::HILOS_I18N_EMAILS => true,
        HilosPageConstants::HILOS_I18N_LANGUAGE => true,
        HilosPageConstants::HILOS_I18N_COUNTRY => true,
        HilosPageConstants::HILOS_I18N_UI_PAGE => true,
        HilosPageConstants::HILOS_I18N_GROUP => true,
        HilosPageConstants::HILOS_I18N_ACTION => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_ENTITY => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR => true,
        HilosPageConstants::HILOS_I18N_TRANSLATE_EMAIL => true,
        HilosPageConstants::HILOS_GUARDIAN => true,
        HilosPageConstants::HILOS_GUARDIAN_AGENT => true,
        HilosPageConstants::HILOS_ANALYTICS => true,
        HilosPageConstants::HILOS_BACKUP => true,
        HilosPageConstants::HILOS_DAEMON => true,
        HilosPageConstants::HILOS_DAEMON_WORKERS => true,
        HilosPageConstants::HILOS_DAEMON_AGENTS => true,
        HilosPageConstants::HILOS_DAEMON_CRON => true,
        HilosPageConstants::HILOS_DAEMON_WEBSOCKETS => true,
        HilosPageConstants::HILOS_DAEMON_HTTP_SERVER => true,
        HilosPageConstants::HILOS_LOGS => true,
        HilosPageConstants::HILOS_LOGS_KEYS => true,
        HilosPageConstants::HILOS_LOGS_WORKERS => true,
        HilosPageConstants::HILOS_LOGS_ROTATIONS => true,
        HilosPageConstants::HILOS_LOGS_VIEW => true,
        HilosPageConstants::HILOS_OPERATIONS => true,
        HilosPageConstants::HILOS_USERS => true,
        HilosPageConstants::HILOS_USER => true,
        HilosPageConstants::HILOS_NOTIFICATIONS => true,
        HilosPageConstants::HILOS_ROLES => true,
        HilosPageConstants::HILOS_MCP_SKILLS => true,
        HilosPageConstants::HILOS_MCP_SKILLS_MCP => true,
        HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS => true,
        HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW => true,
        HilosPageConstants::HILOS_SIL => true,
        HilosPageConstants::HILOS_SIL_REQUESTS => true,
        HilosPageConstants::HILOS_SIL_USER_HISTORY => true,
        HilosPageConstants::HILOS_COMMUNICATIONS => true,
        HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => true,
        HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES => true,
        HilosPageConstants::HILOS_SECURITY => true,
        HilosPageConstants::HILOS_SECURITY_2FA => true,
        HilosPageConstants::HILOS_SECURITY_OAUTH => true,
        HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => true,
        HilosPageConstants::HILOS_BILLING => true,
        HilosPageConstants::HILOS_BILLING_PROVIDER => true,
        HilosPageConstants::HILOS_BILLING_PAYMENTS => true,
        HilosPageConstants::HILOS_BILLING_REFUNDS => true,
        HilosPageConstants::HILOS_CHANGE_LOG => true,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLES => true,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE => true,
        HilosPageConstants::HILOS_ABOUT => true,
        HilosPageConstants::HILOS_TERMS => true,
        HilosPageConstants::HILOS_PRIVACY => true,
        HilosPageConstants::HILOS_LICENSE => true,
    ];

    /**
     * Creates a page factory bound to a project topology registry.
     *
     * @param PageAgentInterface $agent Agent instance
     * @param class-string<Hilos> $hilosClass Active project Hilos facade class
     */
    public function __construct(PageAgentInterface $agent, protected string $hilosClass)
    {
        parent::__construct($agent);
    }

    /**
     * Create page instance from the project topology registry.
     *
     * @param string $pageName Page constant
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When the page is unregistered, maps to a non-AbstractPage class,
     *     or is a known Hilos id without a project implementation
     */
    protected function createPage(string $pageName): AbstractPage
    {
        $pageClass = $this->hilosClass::PAGES[$pageName] ?? null;
        if (is_string($pageClass)) {
            if (!is_subclass_of($pageClass, AbstractPage::class)) {
                throw new PageNotFoundException(
                    "Page '{$pageName}' maps to '{$pageClass}', which is not a " . AbstractPage::class . ' subclass.'
                );
            }

            return new $pageClass($this->agent);
        }

        if (isset(self::HILOS_PAGE_IDS[$pageName])) {
            throw new PageNotFoundException(
                "Hilos page '{$pageName}' requires implementation in project. "
                . "Create concrete page extending AbstractHilos*Page in your project (e.g. Demo\\Chat\\Pages\\Hilos\\SettingsPage)."
            );
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Check if page is registered locally or known as a Hilos admin page id.
     *
     * @param string $pageName Page name constant
     * @return bool True if page exists
     */
    public function hasPage(string $pageName): bool
    {
        return isset($this->hilosClass::PAGES[$pageName])
            || isset(self::HILOS_PAGE_IDS[$pageName]);
    }

    /**
     * Creates action payload DTO from project topology action routes.
     *
     * @param string $action Action name
     * @param array<string, mixed> $data Payload data
     * @return ActionPayloadDTO Action payload DTO
     * @throws ValidationException When the declared DTO refuses this payload
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        $dtoClass = $this->hilosClass::getActionDtoRoutes()[$action] ?? null;
        if (is_string($dtoClass) && is_subclass_of($dtoClass, ActionPayloadDTO::class)) {
            return $dtoClass::fromArray($data);
        }

        return new UnknownActionPayloadDTO($action, $data);
    }

    /**
     * Creates page-routed signal inner payload DTO from project topology.
     *
     * @param string $signalType Signal type constant
     * @param string $signalName Signal name
     * @param SignalDataInterface $payload Inner signal payload from AgentSignalData
     * @return SignalDataInterface Validated or passthrough payload
     * @throws BrokenSignalPayloadDtoException When topology declares a class that cannot be hydrated at all
     * @throws InvalidAgentSignalPayloadException When topology declares a DTO and payload does not match
     */
    public function createPageSignalPayloadDTO(
        string $signalType,
        string $signalName,
        SignalDataInterface $payload,
    ): SignalDataInterface {
        // Registry values are class-strings; anything else means the signal declares no DTO.
        $dtoClass = $this->hilosClass::getPageSignalDtoRoutes()[$signalType][$signalName] ?? null;
        if (!is_string($dtoClass)) {
            return $payload;
        }

        if ($payload instanceof $dtoClass) {
            return $payload;
        }

        try {
            return SignalPayloadHydrator::hydrate($payload->toArray(), $dtoClass, $signalName);
        } catch (BrokenSignalPayloadDtoException $e) {
            // A broken registry entry is not a payload problem; it must not be reported as one.
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidAgentSignalPayloadException($signalName, $dtoClass, $payload, $e);
        }
    }
}
