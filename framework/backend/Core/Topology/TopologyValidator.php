<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;

/**
 * Validates project topology registry constants before runtime layers use them.
 */
final class TopologyValidator
{
    /**
     * Validates topology constants declared by a Hilos facade subclass.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @throws InvalidTopologyException When topology constants are inconsistent
     */
    public function validate(string $hilosClass): void
    {
        $errors = [];
        $pages = $this->constantArray($hilosClass, 'PAGES', $errors);
        $groups = $this->constantArray($hilosClass, 'GROUPS', $errors);
        $agents = $this->constantArray($hilosClass, 'AGENTS', $errors);
        $tables = $this->constantArray($hilosClass, 'TABLES', $errors);
        $browserTables = $this->constantArray($hilosClass, 'BROWSER_TABLES', $errors);
        $pageTables = $this->constantArray($hilosClass, 'PAGE_TABLES', $errors);

        $this->validatePages($pages, $errors);
        $this->validateGroups($groups, $errors);
        $this->validateAgents($agents, $errors);
        $this->validateRegisteredTables($tables, $errors);
        $this->validateBrowserTables($browserTables, $errors);
        $this->validatePageRoutes($pages, $hilosClass::getPageRoutes(), $errors);
        $this->validateGroupRoutes($groups, $hilosClass::getGroupRoutes(), $errors);
        $this->validatePageActionRoutes($pages, $hilosClass::getPageActionRoutes(), $errors);
        $this->validateActionDtoRoutes($pages, $hilosClass::getActionDtoRoutes(), $errors);
        $this->validatePageSignalRoutes($pages, $hilosClass::getPageSignalRoutes(), $errors);
        $this->validatePageSignalDtoRoutes($pages, $hilosClass::getPageSignalDtoRoutes(), $errors);
        $this->validateAgentSignalRoutes(
            $agents,
            $hilosClass::getAgentSignalRoutes(),
            $hilosClass::getPageSignalAgentRoutes(),
            $errors,
        );
        $this->validateAgentSignalDtoRoutes($agents, $hilosClass::getAgentSignalDtoRoutes(), $errors);
        $this->validatePageTables($pages, $tables, $browserTables, $pageTables, $errors);

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Reads an array topology constant from a facade class.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $constant Constant name
     * @param list<string> $errors Validation error accumulator
     * @return array<mixed, mixed> Constant value, or an empty array when invalid
     */
    private function constantArray(string $hilosClass, string $constant, array &$errors): array
    {
        $name = "{$hilosClass}::{$constant}";
        if (!defined($name)) {
            return [];
        }

        $value = constant($name);
        if (!is_array($value)) {
            $errors[] = "{$constant} must be an array";
            return [];
        }

        return $value;
    }

    /**
     * Validates page registry keys and page classes.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePages(array $pages, array &$errors): void
    {
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page)) {
                $errors[] = 'PAGES contains a non-string page key';
                continue;
            }

            if (!$this->isExistingClassString($pageClass, "PAGES[{$page}]", $errors)) {
                continue;
            }

            if (!is_subclass_of($pageClass, AbstractPage::class)) {
                $errors[] = "PAGES[{$page}] class {$pageClass} must extend " . AbstractPage::class;
                continue;
            }

            /** @var class-string<AbstractPage> $pageClass */
            $classPage = $pageClass::PAGE;
            if ($classPage !== $page) {
                $errors[] = "PAGES[{$page}] key must match {$pageClass}::PAGE ({$classPage})";
            }

            if (array_key_exists(BrowserConfigKey::TABLES, $pageClass::BROWSER)) {
                $errors[] = "PAGES[{$page}] class {$pageClass} must declare page-table bindings in PAGE_TABLES, not BROWSER['" . BrowserConfigKey::TABLES . "']";
            }
        }
    }

    /**
     * Validates group registry keys and group classes.
     *
     * @param array<mixed, mixed> $groups Group registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateGroups(array $groups, array &$errors): void
    {
        foreach ($groups as $group => $groupClass) {
            if (!is_string($group)) {
                $errors[] = 'GROUPS contains a non-string group key';
                continue;
            }

            if (!$this->isExistingClassString($groupClass, "GROUPS[{$group}]", $errors)) {
                continue;
            }

            if (!is_subclass_of($groupClass, AbstractGroup::class)) {
                $errors[] = "GROUPS[{$group}] class {$groupClass} must extend " . AbstractGroup::class;
                continue;
            }

            /** @var class-string<AbstractGroup> $groupClass */
            $classGroup = $groupClass::GROUP;
            if ($classGroup !== $group) {
                $errors[] = "GROUPS[{$group}] key must match {$groupClass}::GROUP ({$classGroup})";
            }
        }
    }

    /**
     * Validates computed group route declarations against registered groups.
     *
     * @param array<mixed, mixed> $groups Group registry
     * @param array<mixed, mixed> $groupRoutes Computed group route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateGroupRoutes(array $groups, array $groupRoutes, array &$errors): void
    {
        foreach ($groups as $group => $groupClass) {
            if (!is_string($group)) {
                continue;
            }

            if (!array_key_exists($group, $groupRoutes)) {
                $errors[] = "GROUPS[{$group}] is missing from computed group routes";
                continue;
            }

            $agentType = $groupRoutes[$group];
            if (!is_string($agentType) || $agentType === '') {
                if (is_string($groupClass)) {
                    $errors[] = "GROUPS[{$group}] class {$groupClass} must declare a non-empty SUBSCRIPTION_AGENT_TYPE";
                } else {
                    $errors[] = "GROUPS[{$group}] must declare a non-empty SUBSCRIPTION_AGENT_TYPE";
                }
            }
        }

        foreach ($groupRoutes as $group => $_agentType) {
            if (!is_string($group)) {
                $errors[] = 'Computed group routes contain a non-string group key';
                continue;
            }

            if (!array_key_exists($group, $groups)) {
                $errors[] = "Computed group route {$group} references a group missing from GROUPS";
            }
        }
    }

    /**
     * Validates agent registry keys and agent classes.
     *
     * @param array<mixed, mixed> $agents Agent registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgents(array $agents, array &$errors): void
    {
        foreach ($agents as $agentType => $registryEntry) {
            if (!is_string($agentType) || $agentType === '') {
                $errors[] = 'AGENTS contains a non-string or empty agent type key';
                continue;
            }

            if (!is_array($registryEntry)) {
                $errors[] = "AGENTS[{$agentType}] must be an array declaring worker and daemon classes";
                continue;
            }

            $unknownKeys = array_diff(array_keys($registryEntry), AgentRegistry::ALLOWED_CONFIG_KEYS);
            if ($unknownKeys !== []) {
                $errors[] = 'AGENTS[' . $agentType . '] contains unknown config keys: ' . implode(', ', $unknownKeys);
            }

            $workerClass = AgentRegistry::workerClass($registryEntry);
            if (
                $workerClass === null
                || !$this->isExistingClassString(
                    $workerClass,
                    'AGENTS[' . $agentType . '][' . AgentRegistryKey::WORKER . ']',
                    $errors,
                )
            ) {
                continue;
            }

            if (!is_subclass_of($workerClass, AbstractAgent::class)) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::WORKER . "] class {$workerClass} must extend "
                    . AbstractAgent::class;
                continue;
            }

            /** @var class-string<AbstractAgent> $workerClass */
            $classAgentType = $workerClass::AGENT_TYPE;
            if ($classAgentType !== $agentType) {
                $errors[] = "AGENTS[{$agentType}] key must match {$workerClass}::AGENT_TYPE ({$classAgentType})";
            }

            $daemonClass = AgentRegistry::daemonClass($registryEntry);
            if (
                $daemonClass === null
                || !$this->isExistingClassString(
                    $daemonClass,
                    'AGENTS[' . $agentType . '][' . AgentRegistryKey::DAEMON . ']',
                    $errors,
                )
            ) {
                continue;
            }

            if (!is_subclass_of($daemonClass, AbstractAgentDaemon::class)) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::DAEMON . "] class {$daemonClass} must extend "
                    . AbstractAgentDaemon::class;
                continue;
            }

            /** @var class-string<AbstractAgentDaemon> $daemonClass */
            $daemonAgentType = $daemonClass::AGENT_TYPE;
            if (is_string($daemonAgentType) && $daemonAgentType !== '' && $daemonAgentType !== $agentType) {
                $errors[] = "AGENTS[{$agentType}][" . AgentRegistryKey::DAEMON . "] class {$daemonClass} AGENT_TYPE must match registry key ({$daemonAgentType})";
            }

            $indexed = $registryEntry[AgentRegistryKey::INDEXED] ?? false;
            if ($indexed !== false && !is_bool($indexed)) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::INDEXED . '] must be a boolean';
            }
        }
    }

    /**
     * Validates registered table classes.
     *
     * @param array<mixed, mixed> $tables Registered table registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateRegisteredTables(array $tables, array &$errors): void
    {
        foreach ($tables as $table => $tableClass) {
            if (!is_string($table)) {
                $errors[] = 'TABLES contains a non-string table key';
                continue;
            }

            if (!$this->isExistingClassString($tableClass, "TABLES[{$table}]", $errors)) {
                continue;
            }

            if (!is_subclass_of($tableClass, TableDefinition::class)) {
                $errors[] = "TABLES[{$table}] class {$tableClass} must extend " . TableDefinition::class;
            }
        }
    }

    /**
     * Validates browser-only table registry keys and config classes.
     *
     * @param array<mixed, mixed> $browserTables Browser-only table registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserTables(array $browserTables, array &$errors): void
    {
        foreach ($browserTables as $table => $tableClass) {
            if (!is_string($table)) {
                $errors[] = 'BROWSER_TABLES contains a non-string table key';
                continue;
            }

            if (!$this->isExistingClassString($tableClass, "BROWSER_TABLES[{$table}]", $errors)) {
                continue;
            }

            if (!defined("{$tableClass}::TABLE")) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass} must declare TABLE";
                continue;
            }

            $classTable = constant("{$tableClass}::TABLE");
            if (!is_string($classTable)) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass}::TABLE must be a string";
                continue;
            }

            if ($classTable !== $table) {
                $errors[] = "BROWSER_TABLES[{$table}] key must match {$tableClass}::TABLE ({$classTable})";
            }

            if (!defined("{$tableClass}::BROWSER")) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass} must declare BROWSER";
                continue;
            }

            if (!is_array(constant("{$tableClass}::BROWSER"))) {
                $errors[] = "BROWSER_TABLES[{$table}] class {$tableClass}::BROWSER must be an array";
            }
        }
    }

    /**
     * Validates computed page route declarations against registered pages.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $pageRoutes Computed page route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageRoutes(array $pages, array $pageRoutes, array &$errors): void
    {
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page)) {
                continue;
            }

            if (!array_key_exists($page, $pageRoutes)) {
                $errors[] = "PAGES[{$page}] is missing from computed page routes";
                continue;
            }

            $agentType = $pageRoutes[$page];
            if (!is_string($agentType) || $agentType === '') {
                if (is_string($pageClass)) {
                    $errors[] = "PAGES[{$page}] class {$pageClass} must declare a non-empty SUBSCRIPTION_AGENT_TYPE";
                } else {
                    $errors[] = "PAGES[{$page}] must declare a non-empty SUBSCRIPTION_AGENT_TYPE";
                }
            }
        }

        foreach ($pageRoutes as $page => $_agentType) {
            if (!is_string($page)) {
                $errors[] = 'Computed page routes contain a non-string page key';
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "Computed page route {$page} references a page missing from PAGES";
            }
        }
    }

    /**
     * Validates page-owned WebSocket action route declarations.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $pageActionRoutes Computed action route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageActionRoutes(array $pages, array $pageActionRoutes, array &$errors): void
    {
        $declaredRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::ACTIONS as $action => $dtoClass) {
                if (!is_string($action) || $action === '') {
                    $errors[] = "PAGES[{$page}] class {$pageClass} ACTIONS must use non-empty action name keys";
                    continue;
                }

                if (!$this->isExistingClassString(
                    $dtoClass,
                    "PAGES[{$page}] class {$pageClass} ACTIONS[{$action}]",
                    $errors,
                )) {
                    continue;
                }

                if (!is_subclass_of($dtoClass, ActionPayloadDTO::class)) {
                    $errors[] = "PAGES[{$page}] class {$pageClass} ACTIONS[{$action}] class {$dtoClass} must extend "
                        . ActionPayloadDTO::class;
                    continue;
                }

                if (isset($declaredRoutes[$action]) && $declaredRoutes[$action] !== $page) {
                    $errors[] = "Action {$action} is declared by multiple pages: {$declaredRoutes[$action]} and {$page}";
                    continue;
                }

                $declaredRoutes[$action] = $page;
            }
        }

        foreach ($declaredRoutes as $action => $page) {
            if (($pageActionRoutes[$action] ?? null) !== $page) {
                $errors[] = "Page action route {$action} is missing from computed action routes";
            }
        }

        foreach ($pageActionRoutes as $action => $page) {
            if (!is_string($action) || $action === '') {
                $errors[] = 'Computed page action routes contain a non-string or empty action key';
                continue;
            }

            if (!is_string($page) || $page === '') {
                $errors[] = "Computed page action route {$action} must reference a non-empty page";
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "Computed page action route {$action} references a page missing from PAGES";
            }
        }
    }

    /**
     * Validates page-owned WebSocket action payload DTO route declarations.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $actionDtoRoutes Computed action DTO route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateActionDtoRoutes(array $pages, array $actionDtoRoutes, array &$errors): void
    {
        $declaredRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::ACTIONS as $action => $dtoClass) {
                if (!is_string($action) || $action === '' || !is_string($dtoClass)) {
                    continue;
                }

                $declaredRoutes[$action] = $dtoClass;
            }
        }

        foreach ($declaredRoutes as $action => $dtoClass) {
            if (($actionDtoRoutes[$action] ?? null) !== $dtoClass) {
                $errors[] = "Action DTO route {$action} is missing from computed action DTO routes";
            }
        }

        foreach ($actionDtoRoutes as $action => $dtoClass) {
            if (!is_string($action) || $action === '') {
                $errors[] = 'Computed action DTO routes contain a non-string or empty action key';
                continue;
            }

            if (!is_string($dtoClass) || $dtoClass === '' || !is_subclass_of($dtoClass, ActionPayloadDTO::class)) {
                $errors[] = "Computed action DTO route {$action} must reference a valid ActionPayloadDTO class";
            }
        }
    }

    /**
     * Validates page-owned non-action signal route declarations.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $pageSignalRoutes Computed page signal route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageSignalRoutes(array $pages, array $pageSignalRoutes, array &$errors): void
    {
        $typeWideRoutes = [];
        $namedRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if (!is_string($signalType) || $signalType === '') {
                    $errors[] = "PAGES[{$page}] class {$pageClass} SIGNALS must use non-empty signal type strings";
                    continue;
                }

                if (!is_array($signalNames)) {
                    $errors[] = "PAGES[{$page}] class {$pageClass} SIGNALS[{$signalType}] must be an array";
                    continue;
                }

                if ($signalNames === []) {
                    if (isset($namedRoutes[$signalType])) {
                        $errors[] = "Page signal type {$signalType} is declared as both type-wide and named routes";
                        continue;
                    }

                    if (isset($typeWideRoutes[$signalType]) && $typeWideRoutes[$signalType] !== $page) {
                        $errors[] = "Page signal type {$signalType} is declared by multiple pages: {$typeWideRoutes[$signalType]} and {$page}";
                        continue;
                    }

                    $typeWideRoutes[$signalType] = $page;
                    continue;
                }

                if (isset($typeWideRoutes[$signalType])) {
                    $errors[] = "Page signal type {$signalType} is declared as both type-wide and named routes";
                    continue;
                }

                foreach ($signalNames as $key => $entry) {
                    $signalName = PageSignalRouteRegistry::resolveRouteName($key, $entry);
                    if ($signalName === null) {
                        $errors[] = "PAGES[{$page}] class {$pageClass} SIGNALS[{$signalType}] must contain only non-empty signal names or valid signal DTO map entries";
                        continue;
                    }

                    if (is_string($key) && $key !== '' && is_string($entry) && $entry !== '') {
                        if (!$this->isExistingClassString(
                            $entry,
                            "PAGES[{$page}] class {$pageClass} SIGNALS[{$signalType}][{$signalName}]",
                            $errors,
                        )) {
                            continue;
                        }

                        if (!is_subclass_of($entry, SignalDataInterface::class)) {
                            $errors[] = "PAGES[{$page}] class {$pageClass} SIGNALS[{$signalType}][{$signalName}] class {$entry} must implement "
                                . SignalDataInterface::class;
                        }
                    }

                    if (isset($namedRoutes[$signalType][$signalName]) && $namedRoutes[$signalType][$signalName] !== $page) {
                        $errors[] = "Page signal {$signalType}/{$signalName} is declared by multiple pages: {$namedRoutes[$signalType][$signalName]} and {$page}";
                        continue;
                    }

                    $namedRoutes[$signalType][$signalName] = $page;
                }
            }
        }

        foreach ($typeWideRoutes as $signalType => $page) {
            if (($pageSignalRoutes[$signalType] ?? null) !== $page) {
                $errors[] = "Page signal route {$signalType} is missing from computed signal routes";
            }
        }

        foreach ($namedRoutes as $signalType => $routes) {
            foreach ($routes as $signalName => $page) {
                $computedRoutes = $pageSignalRoutes[$signalType] ?? null;
                if (!is_array($computedRoutes) || ($computedRoutes[$signalName] ?? null) !== $page) {
                    $errors[] = "Page signal route {$signalType}/{$signalName} is missing from computed signal routes";
                }
            }
        }

        foreach ($pageSignalRoutes as $signalType => $route) {
            if (!is_string($signalType) || $signalType === '') {
                $errors[] = 'Computed page signal routes contain a non-string or empty signal type key';
                continue;
            }

            if (is_string($route)) {
                if ($route === '' || !array_key_exists($route, $pages)) {
                    $errors[] = "Computed page signal route {$signalType} references a page missing from PAGES";
                }
                continue;
            }

            if (!is_array($route)) {
                $errors[] = "Computed page signal route {$signalType} must reference a page or named page routes";
                continue;
            }

            foreach ($route as $signalName => $page) {
                if (!is_string($signalName) || $signalName === '') {
                    $errors[] = "Computed page signal route {$signalType} contains a non-string or empty signal name";
                    continue;
                }

                if (!is_string($page) || $page === '' || !array_key_exists($page, $pages)) {
                    $errors[] = "Computed page signal route {$signalType}/{$signalName} references a page missing from PAGES";
                }
            }
        }
    }

    /**
     * Validates page-owned named signal inner payload DTO route declarations.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $pageSignalDtoRoutes Computed page signal DTO route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageSignalDtoRoutes(array $pages, array $pageSignalDtoRoutes, array &$errors): void
    {
        $declaredRoutes = PageSignalRouteRegistry::dtoRoutes($pages);

        foreach ($declaredRoutes as $signalType => $routes) {
            foreach ($routes as $signalName => $dtoClass) {
                if (($pageSignalDtoRoutes[$signalType][$signalName] ?? null) !== $dtoClass) {
                    $errors[] = "Page signal DTO route {$signalType}/{$signalName} is missing from computed page signal DTO routes";
                }
            }
        }

        foreach ($pageSignalDtoRoutes as $signalType => $routes) {
            if (!is_string($signalType) || $signalType === '') {
                $errors[] = 'Computed page signal DTO routes contain a non-string or empty signal type key';
                continue;
            }

            if (!is_array($routes)) {
                $errors[] = "Computed page signal DTO route {$signalType} must be a named route map";
                continue;
            }

            foreach ($routes as $signalName => $dtoClass) {
                if (!is_string($signalName) || $signalName === '') {
                    $errors[] = "Computed page signal DTO route {$signalType} contains a non-string or empty signal name";
                    continue;
                }

                if (!is_string($dtoClass) || $dtoClass === '' || !is_subclass_of($dtoClass, SignalDataInterface::class)) {
                    $errors[] = "Computed page signal DTO route {$signalType}/{$signalName} must reference a valid SignalDataInterface class";
                }
            }
        }
    }

    /**
     * Validates agent-owned agent signal route declarations.
     *
     * @param array<mixed, mixed> $agents Agent registry
     * @param array<mixed, mixed> $agentSignalRoutes Computed agent signal route registry
     * @param array<mixed, mixed> $pageSignalAgentRoutes Computed page signal owner agent routes
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgentSignalRoutes(
        array $agents,
        array $agentSignalRoutes,
        array $pageSignalAgentRoutes,
        array &$errors,
    ): void {
        $declaredRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                // Singleton: list entry — int key + non-empty string signal name.
                if (is_int($key)) {
                    if (!is_string($value) || $value === '') {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS must contain only non-empty signal names or valid indexed config arrays";
                        continue;
                    }

                    $signalName = $value;
                    if (isset($declaredRoutes[$signalName]) && $declaredRoutes[$signalName] !== $agentType) {
                        $errors[] = "Agent signal {$signalName} is declared by multiple agents: {$declaredRoutes[$signalName]} and {$agentType}";
                        continue;
                    }

                    $declaredRoutes[$signalName] = $agentType;
                    continue;
                }

                if (!is_string($key) || $key === '') {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS must contain only non-empty signal names, valid signal DTO map entries, or valid indexed config arrays";
                    continue;
                }

                // Singleton DTO: map entry — non-empty string key + class-string value.
                if (is_string($value) && $value !== '') {
                    if (!$this->isExistingClassString(
                        $value,
                        "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}]",
                        $errors,
                    )) {
                        continue;
                    }

                    if (!is_subclass_of($value, SignalDataInterface::class)) {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] class {$value} must implement "
                            . SignalDataInterface::class;
                    }

                    $signalName = $key;
                    if (isset($declaredRoutes[$signalName]) && $declaredRoutes[$signalName] !== $agentType) {
                        $errors[] = "Agent signal {$signalName} is declared by multiple agents: {$declaredRoutes[$signalName]} and {$agentType}";
                        continue;
                    }

                    $declaredRoutes[$signalName] = $agentType;
                    continue;
                }

                // Indexed: map entry — non-empty string key + array config.
                if (!is_array($value)) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] must be a non-empty signal name, a SignalDataInterface class, or a valid indexed config array";
                    continue;
                }

                $unknownKeys = array_diff(array_keys($value), [AgentSignalConfigKey::INDEX_FIELD, AgentSignalConfigKey::DTO]);
                if ($unknownKeys !== []) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] contains unknown config keys: " . implode(', ', $unknownKeys);
                }

                $indexField = $value[AgentSignalConfigKey::INDEX_FIELD] ?? null;
                if (!is_string($indexField) || $indexField === '') {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] must declare a non-empty '" . AgentSignalConfigKey::INDEX_FIELD . "'";
                }

                $dtoClass = $value[AgentSignalConfigKey::DTO] ?? null;
                if ($dtoClass !== null) {
                    if (!$this->isExistingClassString(
                        $dtoClass,
                        "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}][" . AgentSignalConfigKey::DTO . ']',
                        $errors,
                    )) {
                        // Continue validating route ownership even when DTO class is invalid.
                    } elseif (!is_subclass_of($dtoClass, SignalDataInterface::class)) {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}][" . AgentSignalConfigKey::DTO . "] class {$dtoClass} must implement "
                            . SignalDataInterface::class;
                    }
                }

                $signalName = $key;
                if (isset($declaredRoutes[$signalName]) && $declaredRoutes[$signalName] !== $agentType) {
                    $errors[] = "Agent signal {$signalName} is declared by multiple agents: {$declaredRoutes[$signalName]} and {$agentType}";
                    continue;
                }

                $declaredRoutes[$signalName] = $agentType;
            }
        }

        foreach ($declaredRoutes as $signalName => $agentType) {
            if (($agentSignalRoutes[$signalName] ?? null) !== $agentType) {
                $errors[] = "Agent signal route {$signalName} is missing from computed agent signal routes";
            }
        }

        foreach ($agentSignalRoutes as $signalName => $agentType) {
            if (!is_string($signalName) || $signalName === '') {
                $errors[] = 'Computed agent signal routes contain a non-string or empty signal name';
                continue;
            }

            if (!is_string($agentType) || $agentType === '' || !array_key_exists($agentType, $agents)) {
                $errors[] = "Computed agent signal route {$signalName} references an agent missing from AGENTS";
            }
        }

        $pageAgentSignalRoutes = $pageSignalAgentRoutes[SignalTypeConstants::AGENT_SIGNAL] ?? [];
        if (is_string($pageAgentSignalRoutes) && $declaredRoutes !== []) {
            $errors[] = 'Type-wide page-owned AGENT_SIGNAL route conflicts with agent-owned signal routes';
            return;
        }

        if (!is_array($pageAgentSignalRoutes)) {
            return;
        }

        foreach ($pageAgentSignalRoutes as $signalName => $_agentType) {
            if (isset($declaredRoutes[$signalName])) {
                $errors[] = "Agent signal {$signalName} is declared by both page-owned and agent-owned routes";
            }
        }
    }

    /**
     * Validates agent-owned signal inner payload DTO route declarations.
     *
     * @param array<mixed, mixed> $agents Agent registry
     * @param array<mixed, mixed> $agentSignalDtoRoutes Computed agent signal DTO route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgentSignalDtoRoutes(array $agents, array $agentSignalDtoRoutes, array &$errors): void
    {
        $declaredRoutes = AgentSignalRouteRegistry::dtoRoutes($agents);

        foreach ($declaredRoutes as $signalName => $dtoClass) {
            if (($agentSignalDtoRoutes[$signalName] ?? null) !== $dtoClass) {
                $errors[] = "Agent signal DTO route {$signalName} is missing from computed agent signal DTO routes";
            }
        }

        foreach ($agentSignalDtoRoutes as $signalName => $dtoClass) {
            if (!is_string($signalName) || $signalName === '') {
                $errors[] = 'Computed agent signal DTO routes contain a non-string or empty signal name';
                continue;
            }

            if (!is_string($dtoClass) || $dtoClass === '' || !is_subclass_of($dtoClass, SignalDataInterface::class)) {
                $errors[] = "Computed agent signal DTO route {$signalName} must reference a valid SignalDataInterface class";
            }
        }
    }

    /**
     * Validates page-table bindings against registered pages and tables.
     *
     * @param array<mixed, mixed> $pages Page registry
     * @param array<mixed, mixed> $tables Registered table registry
     * @param array<mixed, mixed> $browserTables Browser-only table registry
     * @param array<mixed, mixed> $pageTables Page-table binding registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageTables(
        array $pages,
        array $tables,
        array $browserTables,
        array $pageTables,
        array &$errors,
    ): void {
        foreach ($pageTables as $page => $bindings) {
            if (!is_string($page)) {
                $errors[] = 'PAGE_TABLES contains a non-string page key';
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "PAGE_TABLES[{$page}] references a page missing from PAGES";
            }

            if (!is_array($bindings)) {
                $errors[] = "PAGE_TABLES[{$page}] must be an array of table bindings";
                continue;
            }

            foreach ($bindings as $table => $config) {
                if (!is_string($table)) {
                    $errors[] = "PAGE_TABLES[{$page}] contains a non-string table key";
                    continue;
                }

                if (!array_key_exists($table, $tables) && !array_key_exists($table, $browserTables)) {
                    $errors[] = "PAGE_TABLES[{$page}][{$table}] references a table missing from TABLES and BROWSER_TABLES";
                }

                if (!is_array($config)) {
                    $errors[] = "PAGE_TABLES[{$page}][{$table}] config must be an array";
                }
            }
        }
    }

    /**
     * Checks a topology value is an existing class string.
     *
     * @param mixed $class Candidate class value
     * @param string $path Topology path for error messages
     * @param list<string> $errors Validation error accumulator
     * @return bool True when the value is an existing class string
     */
    private function isExistingClassString(mixed $class, string $path, array &$errors): bool
    {
        if (!is_string($class) || $class === '') {
            $errors[] = "{$path} must be a non-empty class string";
            return false;
        }

        if (!class_exists($class)) {
            $errors[] = "{$path} class {$class} does not exist";
            return false;
        }

        return true;
    }
}
