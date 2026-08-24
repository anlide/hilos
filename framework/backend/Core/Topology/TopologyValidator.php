<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceKind;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;

/**
 * Validates project topology registry constants before runtime layers use them.
 */
final class TopologyValidator
{
    /**
     * Names of the facade topology constants this validator reads twice — once to
     * load the section, once to name it in an error. The four sections it reads a
     * single time stay literals: a name read in one place describes itself.
     */
    private const string SECTION_BROWSER_TABLES = 'BROWSER_TABLES';

    private const string SECTION_BROWSER_LISTS = 'BROWSER_LISTS';

    private const string SECTION_BROWSER_DATA = 'BROWSER_DATA';

    private const string SECTION_PAGE_TABLES = 'PAGE_TABLES';

    private const string SECTION_PAGE_LISTS = 'PAGE_LISTS';

    private const string SECTION_PAGE_DATA = 'PAGE_DATA';

    private const string SECTION_PROTECTED_MODE_STUB = 'PROTECTED_MODE_STUB';

    private const string SECTION_PAGE_CATALOG = 'PAGE_CATALOG';

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
        $browserTables = $this->constantArray($hilosClass, self::SECTION_BROWSER_TABLES, $errors);
        $browserLists = $this->constantArray($hilosClass, self::SECTION_BROWSER_LISTS, $errors);
        $browserData = $this->constantArray($hilosClass, self::SECTION_BROWSER_DATA, $errors);
        $pageTables = $this->constantArray($hilosClass, self::SECTION_PAGE_TABLES, $errors);
        $pageLists = $this->constantArray($hilosClass, self::SECTION_PAGE_LISTS, $errors);
        $pageData = $this->constantArray($hilosClass, self::SECTION_PAGE_DATA, $errors);
        $browserSources = $browserLists + $browserTables + $browserData;

        $this->validatePages($pages, $errors);
        $this->validateGroups($groups, $errors);
        $this->validateAgents($agents, $errors);
        $this->validateRegisteredTables($tables, $errors);
        $this->validateBrowserTables($browserTables, self::SECTION_BROWSER_TABLES, $errors);
        $this->validateBrowserTables($browserLists, self::SECTION_BROWSER_LISTS, $errors);
        $this->validateBrowserTables($browserData, self::SECTION_BROWSER_DATA, $errors);
        $this->validatePageBrowserConfigs($pages, $errors);
        $this->validateBrowserSourceConfigs($browserTables, self::SECTION_BROWSER_TABLES, $errors);
        $this->validateBrowserSourceConfigs($browserLists, self::SECTION_BROWSER_LISTS, $errors);
        $this->validateBrowserSourceConfigs($browserData, self::SECTION_BROWSER_DATA, $errors);
        $this->validatePageRoutes($pages, $hilosClass::getPageRoutes(), $errors);
        $this->validatePageAgentIndexRoutes($pages, $agents, $errors);
        $this->validateGroupRoutes($groups, $hilosClass::getGroupRoutes(), $errors);
        $this->validatePageActionRoutes($pages, $hilosClass::getPageActionRoutes(), $errors);
        $this->validateActionDtoRoutes($pages, $hilosClass::getActionDtoRoutes(), $errors);
        $this->validateAgentActionRoutes(
            $agents,
            $hilosClass::getPageActionRoutes(),
            $hilosClass::getAgentActionRoutes(),
            $hilosClass::getAgentActionDtoRoutes(),
            $errors,
        );
        $this->validateAgentActionGuards($agents, $errors);
        $this->validatePageSignalRoutes($pages, $hilosClass::getPageSignalRoutes(), $errors);
        $this->validatePageSignalDtoRoutes($pages, $hilosClass::getPageSignalDtoRoutes(), $errors);
        $this->validateAgentSignalRoutes(
            $agents,
            $hilosClass::getAgentSignalRoutes(),
            $hilosClass::getPageSignalAgentRoutes(),
            $errors,
        );
        $this->validateAgentSignalDtoRoutes($agents, $hilosClass::getAgentSignalDtoRoutes(), $errors);
        $this->validateAgentCommandRoutes($agents, $hilosClass::getCommandAgentRoutes(), $errors);
        $this->validatePageTables($pages, $tables, $browserSources, $pageTables, self::SECTION_PAGE_TABLES, $errors);
        $this->validatePageTables($pages, $tables, $browserSources, $pageLists, self::SECTION_PAGE_LISTS, $errors);
        $this->validatePageTables($pages, $tables, $browserSources, $pageData, self::SECTION_PAGE_DATA, $errors);
        $this->validateBrowserBindings($pages, $browserSources, $pageTables, self::SECTION_PAGE_TABLES, $errors);
        $this->validateBrowserBindings($pages, $browserSources, $pageLists, self::SECTION_PAGE_LISTS, $errors);
        $this->validateBrowserBindings($pages, $browserSources, $pageData, self::SECTION_PAGE_DATA, $errors);
        $this->validateProtectedModeStub(
            Hilos::catalogConstantOf($hilosClass, self::SECTION_PROTECTED_MODE_STUB),
            $errors,
        );
        $this->validatePageCatalog(
            Hilos::catalogConstantOf($hilosClass, self::SECTION_PAGE_CATALOG),
            $errors,
        );

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Validates that every browser source declaration names something the mounted layers hold.
     *
     * The second of the two moments a browser declaration is judged in, and it cannot be folded
     * into the first: {@see self::validate()} runs before `$db` and `$rt` exist, so nothing there
     * can be asked whether a collection is mounted. Called at the end of {@see Hilos::init()},
     * where both layers stand, by the same accumulate-then-throw rule as the first moment.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @throws InvalidTopologyException When a declaration names a collection no layer mounts
     */
    public function validateReferences(string $hilosClass): void
    {
        $errors = [];
        $declarations = [];
        foreach ([self::SECTION_BROWSER_TABLES, self::SECTION_BROWSER_LISTS, self::SECTION_BROWSER_DATA] as $registry) {
            $this->collectBrowserSourceReferences(
                $this->constantArray($hilosClass, $registry, $errors),
                $registry,
                $declarations,
            );
        }

        $this->collectPageGuardReferences($this->constantArray($hilosClass, 'PAGES', $errors), $declarations);

        foreach ($declarations as $identity => $path) {
            [$type, $name] = explode(':', $identity, 2);
            if ($type === BrowserSourceType::DB) {
                if (Hilos::$db?->getObjectCollection($name) === null) {
                    $errors[] = "{$path} source key {$name} is not a mounted db collection";
                }

                continue;
            }

            if ($type !== BrowserSourceType::RT) {
                continue;
            }

            if (Hilos::$rt === null) {
                $errors[] = "{$path} names an rt source, but this project mounts no runtime";
            } elseif (!Hilos::$rt->hasSource($name)) {
                $errors[] = "{$path} source key {$name} is not a mounted rt source";
            }
        }

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Collects the source declarations one browser registry's rows reference.
     *
     * @param array $browserSources Browser source registry (tables, lists, or data)
     * @param string $registry Registry constant name for error messages
     * @param array<string, string> $declarations Identity-to-path accumulator
     */
    private function collectBrowserSourceReferences(
        array $browserSources,
        string $registry,
        array &$declarations,
    ): void {
        foreach ($browserSources as $key => $sourceClass) {
            if (!is_string($key) || !is_string($sourceClass) || !class_exists($sourceClass)) {
                continue;
            }

            $browser = defined("{$sourceClass}::BROWSER") ? constant("{$sourceClass}::BROWSER") : null;
            if (!is_array($browser)) {
                continue;
            }

            $rowsKey = match (true) {
                defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::LIST)) => BrowserListConfigKey::ITEMS,
                defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::DATA)) => BrowserDataConfigKey::ROWS,
                default => BrowserTableConfigKey::ROWS,
            };
            $rows = $browser[$rowsKey] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            $path = "{$registry}[{$key}] class {$sourceClass}::BROWSER";
            foreach ($rows as $index => $row) {
                if (is_array($row)) {
                    $source = $row[BrowserFieldKey::SOURCE] ?? null;
                    $this->rememberBrowserSourceReference($source, "{$path} {$rowsKey}[{$index}]", $declarations);
                }
            }
        }
    }

    /**
     * Collects the source declarations page guards reference.
     *
     * @param array $pages Page registry
     * @param array<string, string> $declarations Identity-to-path accumulator
     */
    private function collectPageGuardReferences(array $pages, array &$declarations): void
    {
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            /** @var class-string<AbstractPage> $pageClass */
            $guards = $pageClass::BROWSER[BrowserConfigKey::GUARDS] ?? [];
            if (!is_array($guards)) {
                continue;
            }

            $path = "PAGES[{$page}] class {$pageClass}::BROWSER";
            foreach ($guards as $index => $guard) {
                if (is_array($guard)) {
                    $source = $guard[BrowserGuardKey::SOURCE] ?? null;
                    $this->rememberBrowserSourceReference($source, "{$path} guards[{$index}]", $declarations);
                }
            }
        }
    }

    /**
     * Remembers one source declaration under its identity, keeping the first path that named it
     * so a collection referenced by ten rows is asked about once and reported once.
     *
     * @param mixed $source Declared source entry
     * @param string $path Topology path of the declaration that referenced it
     * @param array<string, string> $declarations Identity-to-path accumulator
     */
    private function rememberBrowserSourceReference(mixed $source, string $path, array &$declarations): void
    {
        foreach (array_keys($this->browserSourceIdentity($source)) as $identity) {
            $declarations[$identity] ??= $path;
        }
    }

    /**
     * Reads an array topology constant from a facade class.
     *
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param string $constant Constant name
     * @param list<string> $errors Validation error accumulator
     * @return array Constant value, or an empty array when invalid
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
     * @param array $pages Page registry
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
                $errors[] = "PAGES[{$page}] class {$pageClass} must declare page-table bindings in PAGE_TABLES,"
                    . " not BROWSER['" . BrowserConfigKey::TABLES . "']";
            }
        }
    }

    /**
     * Validates group registry keys and group classes.
     *
     * @param array $groups Group registry
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
     * @param array $groups Group registry
     * @param array $groupRoutes Computed group route registry
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
     * @param array $agents Agent registry
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
            if ($daemonAgentType !== '' && $daemonAgentType !== $agentType) {
                $errors[] = "AGENTS[{$agentType}][" . AgentRegistryKey::DAEMON
                    . "] class {$daemonClass} AGENT_TYPE must match registry key ({$daemonAgentType})";
            }

            $indexed = $registryEntry[AgentRegistryKey::INDEXED] ?? false;
            if ($indexed !== false && !is_bool($indexed)) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::INDEXED . '] must be a boolean';
            }

            $scope = $registryEntry[AgentRegistryKey::SCOPE] ?? null;
            if ($scope !== null && !$scope instanceof AgentScope) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::SCOPE . '] must be a '
                    . AgentScope::class . ' case';
            } elseif ($scope === AgentScope::NODE && $indexed === true) {
                $errors[] = 'AGENTS[' . $agentType . '] cannot combine scope '
                    . AgentScope::NODE->name . ' with ' . AgentRegistryKey::INDEXED
                    . ': a sharded pool needs an index, an every-node replica has none';
            }

            $placement = $registryEntry[AgentRegistryKey::PLACEMENT] ?? null;
            if ($placement !== null && !$placement instanceof AgentPlacement) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::PLACEMENT . '] must be a '
                    . AgentPlacement::class . ' case';
            } elseif ($placement !== null && $scope === AgentScope::NODE) {
                $errors[] = 'AGENTS[' . $agentType . '] cannot set ' . AgentRegistryKey::PLACEMENT
                    . ' with scope ' . AgentScope::NODE->name
                    . ': a replica runs on every node, so no node is picked';
            }
        }
    }

    /**
     * Validates registered table classes.
     *
     * @param array $tables Registered table registry
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
     * Validates a browser source registry's keys and config classes.
     *
     * @param array $browserTables Browser source registry (tables, lists, or data)
     * @param string $registry Registry constant name for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserTables(array $browserTables, string $registry, array &$errors): void
    {
        foreach ($browserTables as $table => $tableClass) {
            if (!is_string($table)) {
                $errors[] = "{$registry} contains a non-string table key";
                continue;
            }

            if (!$this->isExistingClassString($tableClass, "{$registry}[{$table}]", $errors)) {
                continue;
            }

            $keyConstName = null;
            foreach ([BrowserSourceKind::TABLE, BrowserSourceKind::LIST, BrowserSourceKind::DATA] as $kind) {
                if (defined("{$tableClass}::" . strtoupper($kind))) {
                    $keyConstName = strtoupper($kind);
                    break;
                }
            }
            if ($keyConstName === null) {
                $errors[] = "{$registry}[{$table}] class {$tableClass} must declare a source key constant (TABLE, LIST, or DATA)";
                continue;
            }

            $classTable = constant("{$tableClass}::{$keyConstName}");
            if (!is_string($classTable)) {
                $errors[] = "{$registry}[{$table}] class {$tableClass}::{$keyConstName} must be a string";
                continue;
            }

            if ($classTable !== $table) {
                $errors[] = "{$registry}[{$table}] key must match {$tableClass}::{$keyConstName} ({$classTable})";
            }

            if (!defined("{$tableClass}::BROWSER")) {
                $errors[] = "{$registry}[{$table}] class {$tableClass} must declare BROWSER";
                continue;
            }

            if (!is_array(constant("{$tableClass}::BROWSER"))) {
                $errors[] = "{$registry}[{$table}] class {$tableClass}::BROWSER must be an array";
            }
        }
    }

    /**
     * Validates the contents of every registered page's BROWSER declaration.
     *
     * Judged at startup for the same reason the protected-mode stub is: a browser declaration
     * has no earlier reader than a subscription, so a misspelled key reaches the developer as
     * an end user's error or blank screen instead of as a node that refuses to come up.
     *
     * The `tables` key is left out of the unknown-key report on purpose - {@see
     * self::validatePages()} already answers it with a message naming PAGE_TABLES as the place
     * bindings belong, and one mistake deserves one sentence.
     *
     * @param array $pages Page registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageBrowserConfigs(array $pages, array &$errors): void
    {
        $knownKeys = [
            BrowserConfigKey::SIGNAL,
            BrowserConfigKey::PARAMS,
            BrowserConfigKey::GUARDS,
            BrowserConfigKey::TABLES,
        ];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            /** @var class-string<AbstractPage> $pageClass */
            $browser = $pageClass::BROWSER;
            $path = "PAGES[{$page}] class {$pageClass}::BROWSER";
            foreach (array_keys($browser) as $declaredKey) {
                if (!in_array($declaredKey, $knownKeys, true)) {
                    $errors[] = "{$path} declares an unknown key {$declaredKey}";
                }
            }

            $signal = $browser[BrowserConfigKey::SIGNAL] ?? null;
            if ($signal !== null && (!is_string($signal) || $signal === '')) {
                $errors[] = "{$path} signal must be a non-empty string";
            }

            $this->validateBrowserParamDeclarations($browser[BrowserConfigKey::PARAMS] ?? [], $path, $errors);

            $guards = $browser[BrowserConfigKey::GUARDS] ?? [];
            if (!is_array($guards)) {
                $errors[] = "{$path} guards must be a list of guard declarations";
                continue;
            }

            foreach ($guards as $index => $guard) {
                $this->validateBrowserGuard($guard, "{$path} guards[{$index}]", $errors);
            }
        }
    }

    /**
     * Validates the contents of every BROWSER declaration in one browser source registry.
     *
     * The kind constant a source class declares (LIST, DATA, or TABLE) decides which dictionary
     * names its keys, so a list declaring `rows` reads as an unknown key rather than as a table
     * that happens to work.
     *
     * @param array $browserSources Browser source registry (tables, lists, or data)
     * @param string $registry Registry constant name for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserSourceConfigs(array $browserSources, string $registry, array &$errors): void
    {
        foreach ($browserSources as $key => $sourceClass) {
            if (!is_string($key) || !is_string($sourceClass) || !class_exists($sourceClass)) {
                continue;
            }

            $browser = defined("{$sourceClass}::BROWSER") ? constant("{$sourceClass}::BROWSER") : null;
            if (!is_array($browser)) {
                continue;
            }

            [$sourcesKey, $rowsKey, $paramsKey, $rowKeyKey] = match (true) {
                defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::LIST)) => [
                    BrowserListConfigKey::SOURCES,
                    BrowserListConfigKey::ITEMS,
                    BrowserListConfigKey::PARAMS,
                    BrowserListFieldKey::ITEM_KEY,
                ],
                defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::DATA)) => [
                    BrowserDataConfigKey::SOURCES,
                    BrowserDataConfigKey::ROWS,
                    BrowserDataConfigKey::PARAMS,
                    BrowserDataFieldKey::ROW_KEY,
                ],
                default => [
                    BrowserTableConfigKey::SOURCES,
                    BrowserTableConfigKey::ROWS,
                    BrowserTableConfigKey::PARAMS,
                    BrowserTableFieldKey::ROW_KEY,
                ],
            };

            $path = "{$registry}[{$key}] class {$sourceClass}::BROWSER";
            foreach (array_keys($browser) as $declaredKey) {
                if (!in_array($declaredKey, [$sourcesKey, $rowsKey, $paramsKey], true)) {
                    $errors[] = "{$path} declares an unknown key {$declaredKey}";
                }
            }

            $params = $browser[$paramsKey] ?? [];
            $this->validateBrowserParamDeclarations($params, $path, $errors);
            $declaredParams = is_array($params) ? array_keys($params) : [];

            $rows = $browser[$rowsKey] ?? [];
            if (!is_array($rows)) {
                $errors[] = "{$path} {$rowsKey} must be a list of row declarations";
                continue;
            }

            $referenced = [];
            foreach ($rows as $index => $row) {
                $rowPath = "{$path} {$rowsKey}[{$index}]";
                if (!is_array($row)) {
                    $errors[] = "{$rowPath} must be an array";
                    continue;
                }

                $source = $row[BrowserFieldKey::SOURCE] ?? null;
                $this->validateBrowserSourceDeclaration($source, "{$rowPath} source", $errors);
                $referenced += $this->browserSourceIdentity($source);

                $rowKey = $row[$rowKeyKey] ?? null;
                if (is_array($rowKey)) {
                    $this->validateBrowserRef($rowKey, "{$rowPath} {$rowKeyKey}", $declaredParams, [], $errors);
                } elseif (!is_string($rowKey) || $rowKey === '') {
                    $errors[] = "{$rowPath} must declare a non-empty {$rowKeyKey}";
                }

                foreach ([BrowserFieldKey::FIELDS, BrowserFieldKey::COMPUTED, BrowserFieldKey::TRIGGERS] as $listKey) {
                    if (array_key_exists($listKey, $row) && !is_array($row[$listKey])) {
                        $errors[] = "{$rowPath} {$listKey} must be an array";
                    }
                }

                foreach ([BrowserFieldKey::WHERE, BrowserFieldKey::VIA] as $mapKey) {
                    $map = $row[$mapKey] ?? [];
                    if (!is_array($map)) {
                        $errors[] = "{$rowPath} {$mapKey} must be an array";
                        continue;
                    }

                    foreach ($map as $field => $value) {
                        if (is_array($value)) {
                            $refPath = "{$rowPath} {$mapKey}[{$field}]";
                            $this->validateBrowserRef($value, $refPath, $declaredParams, [], $errors);
                        }
                    }
                }
            }

            $declaredSources = $browser[$sourcesKey] ?? [];
            if (!is_array($declaredSources)) {
                $errors[] = "{$path} {$sourcesKey} must be a list of source declarations";
                continue;
            }

            $listed = [];
            foreach ($declaredSources as $index => $source) {
                $this->validateBrowserSourceDeclaration($source, "{$path} {$sourcesKey}[{$index}]", $errors);
                $listed += $this->browserSourceIdentity($source);
            }

            $missing = array_diff_key($referenced, $listed);
            $unused = array_diff_key($listed, $referenced);
            if ($missing !== [] || $unused !== []) {
                $errors[] = "{$path} {$sourcesKey} must list exactly the sources {$rowsKey} reference"
                    . ' (missing: ' . $this->browserSourceList($missing)
                    . '; unused: ' . $this->browserSourceList($unused) . ')';
            }
        }
    }

    /**
     * Validates one page-to-source binding registry against the params both sides declare.
     *
     * The binding is the only place the two halves of a browser param meet: the source names the
     * params it needs, the page names the params it has, and the binding says which of the
     * second fills which of the first. A name misspelled on either side reads as a param that is
     * simply never filled, which the runtime answers with a null and an empty surface.
     *
     * A binding naming a key from TABLES rather than a browser source is skipped - a registered
     * table definition is judged by its own registry, not by this one.
     *
     * @param array $pages Page registry
     * @param array $browserSources Merged browser source registry
     * @param array $bindings Page binding registry (tables, lists, or data)
     * @param string $registry Registry constant name for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserBindings(
        array $pages,
        array $browserSources,
        array $bindings,
        string $registry,
        array &$errors,
    ): void {
        foreach ($bindings as $page => $pageBindings) {
            if (!is_string($page) || !is_array($pageBindings)) {
                continue;
            }

            $pageClass = $pages[$page] ?? null;
            $pageParams = [];
            if (is_string($pageClass) && is_subclass_of($pageClass, AbstractPage::class)) {
                /** @var class-string<AbstractPage> $pageClass */
                $declared = $pageClass::BROWSER[BrowserConfigKey::PARAMS] ?? [];
                $pageParams = is_array($declared) ? array_keys($declared) : [];
            }

            foreach ($pageBindings as $key => $config) {
                $sourceClass = is_string($key) ? ($browserSources[$key] ?? null) : null;
                if (!is_array($config) || !is_string($sourceClass) || !class_exists($sourceClass)) {
                    continue;
                }

                $browser = defined("{$sourceClass}::BROWSER") ? constant("{$sourceClass}::BROWSER") : null;
                if (!is_array($browser)) {
                    continue;
                }

                $paramsKey = match (true) {
                    defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::LIST)) => BrowserListConfigKey::PARAMS,
                    defined("{$sourceClass}::" . strtoupper(BrowserSourceKind::DATA)) => BrowserDataConfigKey::PARAMS,
                    default => BrowserTableConfigKey::PARAMS,
                };
                $sourceParams = $browser[$paramsKey] ?? [];
                $sourceParams = is_array($sourceParams) ? $sourceParams : [];

                $path = "{$registry}[{$page}][{$key}]";
                $boundParams = $config[BrowserParamKey::PARAMS] ?? [];
                if (!is_array($boundParams)) {
                    $errors[] = "{$path} " . BrowserParamKey::PARAMS . ' must be a map of param references';
                    continue;
                }

                foreach ($boundParams as $name => $ref) {
                    $paramPath = "{$path} " . BrowserParamKey::PARAMS . "[{$name}]";
                    if (!array_key_exists($name, $sourceParams)) {
                        $errors[] = "{$paramPath} is not declared by {$sourceClass}::BROWSER "
                            . BrowserParamKey::PARAMS;
                        continue;
                    }

                    $this->validateBrowserRef($ref, $paramPath, [], $pageParams, $errors);
                }

                foreach ($sourceParams as $name => $declaration) {
                    $required = is_array($declaration) ? ($declaration[BrowserParamKey::REQUIRED] ?? false) : false;
                    if ($required === true && !array_key_exists($name, $boundParams)) {
                        $errors[] = "{$path} does not fill required param {$name} of {$sourceClass}::BROWSER";
                    }
                }
            }
        }
    }

    /**
     * Validates a param declaration map from a page or source BROWSER declaration.
     *
     * The type is required rather than defaulted even though the runtime reads an absent one as
     * `string`: a param whose type is not written down is indistinguishable from one whose type
     * was forgotten, and the forgotten one silently stops coercing an id to an int.
     *
     * @param mixed $params Declared param map
     * @param string $path Topology path for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserParamDeclarations(mixed $params, string $path, array &$errors): void
    {
        if (!is_array($params)) {
            $errors[] = "{$path} " . BrowserParamKey::PARAMS . ' must be a map of param declarations';
            return;
        }

        $knownTypes = [BrowserParamType::STRING, BrowserParamType::POSITIVE_INT];
        foreach ($params as $param => $declaration) {
            $paramPath = "{$path} " . BrowserParamKey::PARAMS . "[{$param}]";
            $type = is_array($declaration) ? ($declaration[BrowserParamKey::TYPE] ?? null) : null;
            if (!in_array($type, $knownTypes, true)) {
                $errors[] = "{$paramPath} must declare type " . implode(' or ', $knownTypes);
            }

            $required = is_array($declaration) ? ($declaration[BrowserParamKey::REQUIRED] ?? false) : false;
            if (!is_bool($required)) {
                $errors[] = "{$paramPath} " . BrowserParamKey::REQUIRED . ' must be a bool';
            }
        }
    }

    /**
     * Validates one page guard declaration: a known type, and the fields that type needs.
     *
     * An `authenticated` guard is refused a source or a field rather than ignoring them, because
     * the runtime reads neither: a guard written as authenticated-with-a-field looks like an
     * access check to the reader and lets every signed-in user through.
     *
     * @param mixed $guard Declared guard entry
     * @param string $path Topology path for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserGuard(mixed $guard, string $path, array &$errors): void
    {
        $knownTypes = [BrowserGuardType::DB_EXISTS, BrowserGuardType::ACCESS, BrowserGuardType::AUTHENTICATED];
        $type = is_array($guard) ? ($guard[BrowserGuardKey::TYPE] ?? null) : null;
        if (!in_array($type, $knownTypes, true)) {
            $errors[] = "{$path} must declare a known type (" . implode(', ', $knownTypes) . ')';
            return;
        }

        /** @var array<string, mixed> $guard */
        if ($type === BrowserGuardType::AUTHENTICATED) {
            if (array_key_exists(BrowserGuardKey::SOURCE, $guard) || array_key_exists(BrowserGuardKey::FIELD, $guard)) {
                $errors[] = "{$path} of type " . BrowserGuardType::AUTHENTICATED . ' must declare neither '
                    . BrowserGuardKey::SOURCE . ' nor ' . BrowserGuardKey::FIELD;
            }

            return;
        }

        $this->validateBrowserSourceDeclaration(
            $guard[BrowserGuardKey::SOURCE] ?? null,
            "{$path} " . BrowserGuardKey::SOURCE,
            $errors,
        );

        if ($type === BrowserGuardType::ACCESS) {
            $field = $guard[BrowserGuardKey::FIELD] ?? null;
            if (!is_string($field) || $field === '') {
                $errors[] = "{$path} of type " . BrowserGuardType::ACCESS . ' must declare a non-empty '
                    . BrowserGuardKey::FIELD;
            }

            return;
        }

        $this->validateBrowserRef(
            $guard[BrowserGuardKey::KEY] ?? null,
            "{$path} " . BrowserGuardKey::KEY,
            [],
            [],
            $errors,
        );

        $knownErrors = [BrowserSubscriptionError::NOT_FOUND, BrowserSubscriptionError::FORBIDDEN];
        if (array_key_exists(BrowserGuardKey::ERROR, $guard)
            && !in_array($guard[BrowserGuardKey::ERROR], $knownErrors, true)) {
            $errors[] = "{$path} " . BrowserGuardKey::ERROR . ' must be one of ' . implode(', ', $knownErrors);
        }
    }

    /**
     * Validates one `{type, key}` source declaration, wherever it is declared.
     *
     * @param mixed $source Declared source entry
     * @param string $path Topology path of the source declaration itself
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserSourceDeclaration(mixed $source, string $path, array &$errors): void
    {
        $key = is_array($source) ? ($source[BrowserSourceKey::KEY] ?? null) : null;
        if (!is_string($key) || $key === '') {
            $errors[] = "{$path} must declare " . BrowserSourceKey::TYPE . ' and ' . BrowserSourceKey::KEY;
            return;
        }

        $knownTypes = [BrowserSourceType::DB, BrowserSourceType::RT];
        if (!in_array($source[BrowserSourceKey::TYPE] ?? null, $knownTypes, true)) {
            $errors[] = "{$path} must declare " . BrowserSourceKey::TYPE . ' ' . implode(' or ', $knownTypes);
        }
    }

    /**
     * Validates one reference declaration and the param name it points at.
     *
     * A `table_param` name is always judged: the params it can name belong to the source that
     * owns the declaration, so an empty list there means the source declares none and the
     * reference is broken. A `page_param` name is judged only where a page is in scope - a
     * source row and a page guard are read without one - and an empty list means no page rather
     * than a page declaring nothing.
     *
     * @param mixed $ref Declared reference
     * @param string $path Topology path of the reference itself
     * @param list<array-key> $declaredTableParams Param names declared by the owning source
     * @param list<array-key> $declaredPageParams Param names declared by the owning page
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserRef(
        mixed $ref,
        string $path,
        array $declaredTableParams,
        array $declaredPageParams,
        array &$errors,
    ): void {
        $knownTypes = [BrowserRefType::ACCEPT_KEY, BrowserRefType::PAGE_PARAM, BrowserRefType::TABLE_PARAM];
        $type = is_array($ref) ? ($ref[BrowserRefKey::TYPE] ?? null) : null;
        if (!in_array($type, $knownTypes, true)) {
            $errors[] = "{$path} ref must declare a known type (" . implode(', ', $knownTypes) . ')';
            return;
        }

        if ($type === BrowserRefType::ACCEPT_KEY) {
            return;
        }

        /** @var array<string, mixed> $ref */
        $name = $ref[BrowserRefKey::KEY] ?? null;
        if (!is_string($name) || $name === '') {
            $errors[] = "{$path} {$type} must declare a non-empty " . BrowserRefKey::KEY;
            return;
        }

        $declared = $type === BrowserRefType::TABLE_PARAM ? $declaredTableParams : $declaredPageParams;
        if ($type === BrowserRefType::PAGE_PARAM && $declared === []) {
            return;
        }

        if (!in_array($name, $declared, true)) {
            $errors[] = "{$path} {$type} {$name} is not declared in " . BrowserParamKey::PARAMS;
        }
    }

    /**
     * Returns a source declaration as a single-entry `type:key => declaration` set, or nothing
     * when the declaration is too broken to name - the set form lets a source referenced by
     * several rows collapse to one entry before the declared and referenced sets are compared.
     *
     * @param mixed $source Declared source entry
     * @return array<string, array<string, mixed>> Identity set of this declaration
     */
    private function browserSourceIdentity(mixed $source): array
    {
        if (!is_array($source)) {
            return [];
        }

        $type = $source[BrowserSourceKey::TYPE] ?? null;
        $key = $source[BrowserSourceKey::KEY] ?? null;
        if (!is_string($type) || !is_string($key) || $key === '') {
            return [];
        }

        return ["{$type}:{$key}" => $source];
    }

    /**
     * Names a set of source identities for an error message.
     *
     * @param array<string, array<string, mixed>> $sources Identity set to name
     * @return string Comma-separated identities, or `none` when the set is empty
     */
    private function browserSourceList(array $sources): string
    {
        return $sources === [] ? 'none' : implode(', ', array_keys($sources));
    }

    /**
     * Validates computed page route declarations against registered pages.
     *
     * @param array $pages Page registry
     * @param array $pageRoutes Computed page route registry
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
     * Validates per-instance page route declarations against registered pages and agents.
     *
     * Judged off the raw declaration and not off the computed registry: the registry drops
     * a malformed entry silently ({@see PageAgentIndexRouteRegistry::routes}), so reading it
     * here would report nothing at all for exactly the pages that need reporting.
     *
     * @param array $pages Page registry
     * @param array $agents Agent registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageAgentIndexRoutes(array $pages, array $agents, array &$errors): void
    {
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            $declaration = $pageClass::SUBSCRIPTION_AGENT_INDEX;
            if ($declaration === []) {
                continue;
            }

            $path = "PAGES[{$page}] class {$pageClass} SUBSCRIPTION_AGENT_INDEX";
            $unknownKeys = array_diff(array_keys($declaration), [
                PageAgentIndexKey::SOURCE,
                PageAgentIndexKey::PARAM,
                PageAgentIndexKey::FALLBACK_AGENT_TYPE,
            ]);
            if ($unknownKeys !== []) {
                $errors[] = "{$path} contains unknown config keys: " . implode(', ', $unknownKeys);
            }

            $source = $declaration[PageAgentIndexKey::SOURCE] ?? null;
            if (!$source instanceof PageAgentIndexSource) {
                $errors[] = "{$path} must declare a '" . PageAgentIndexKey::SOURCE . "' of type "
                    . PageAgentIndexSource::class;
            }

            $param = $declaration[PageAgentIndexKey::PARAM] ?? null;
            if ($source === PageAgentIndexSource::PARAM && (!is_string($param) || $param === '')) {
                $errors[] = "{$path} with source " . PageAgentIndexSource::PARAM->value . " must declare a non-empty '"
                    . PageAgentIndexKey::PARAM . "'";
            }

            $fallbackAgentType = $declaration[PageAgentIndexKey::FALLBACK_AGENT_TYPE] ?? null;
            if (!is_string($fallbackAgentType) || $fallbackAgentType === '') {
                $errors[] = "{$path} must declare a non-empty '" . PageAgentIndexKey::FALLBACK_AGENT_TYPE . "'";
            } elseif (!array_key_exists($fallbackAgentType, $agents)) {
                $errors[] = "{$path} names fallback agent type {$fallbackAgentType}, which is missing from AGENTS";
            }

            // A public per-instance "my" page would hand a guest to the fallback agent, which
            // serves the page for real - the refusal it is there to give would never happen.
            if ($source === PageAgentIndexSource::SESSION_USER && $pageClass::ACCESS_LEVEL === PageAccessLevel::PUBLIC) {
                $errors[] = "{$path} with source " . PageAgentIndexSource::SESSION_USER->value
                    . ' requires ACCESS_LEVEL other than ' . PageAccessLevel::PUBLIC->value;
            }
        }
    }

    /**
     * Validates page-owned WebSocket action route declarations.
     *
     * @param array $pages Page registry
     * @param array $pageActionRoutes Computed action route registry
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
     * @param array $pages Page registry
     * @param array $actionDtoRoutes Computed action DTO route registry
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
     * Validates agent-owned client-action route declarations (AGENT_ACTIONS).
     *
     * Each action must carry a valid ActionPayloadDTO class, must not be declared by
     * more than one agent, and must not collide with a page-owned action name — the
     * client action registry must resolve every action name to a single owner.
     *
     * @param array $agents Agent registry
     * @param array $pageActionRoutes Computed page action route registry
     * @param array $agentActionRoutes Computed agent action route registry
     * @param array $agentActionDtoRoutes Computed agent action DTO route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgentActionRoutes(
        array $agents,
        array $pageActionRoutes,
        array $agentActionRoutes,
        array $agentActionDtoRoutes,
        array &$errors,
    ): void {
        $declaredRoutes = [];
        $declaredDtoRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_ACTIONS as $action => $dtoClass) {
                if (!is_string($action) || $action === '') {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_ACTIONS must use non-empty action name keys";
                    continue;
                }

                if (!$this->isExistingClassString(
                    $dtoClass,
                    "AGENTS[{$agentType}] class {$agentClass} AGENT_ACTIONS[{$action}]",
                    $errors,
                )) {
                    continue;
                }

                if (!is_subclass_of($dtoClass, ActionPayloadDTO::class)) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_ACTIONS[{$action}] class {$dtoClass} must extend "
                        . ActionPayloadDTO::class;
                    continue;
                }

                if (array_key_exists($action, $pageActionRoutes)) {
                    $errors[] = "Action {$action} is declared by both agent {$agentType} and page {$pageActionRoutes[$action]}";
                    continue;
                }

                if (isset($declaredRoutes[$action]) && $declaredRoutes[$action] !== $agentType) {
                    $errors[] = "Action {$action} is declared by multiple agents: {$declaredRoutes[$action]} and {$agentType}";
                    continue;
                }

                $declaredRoutes[$action] = $agentType;
                $declaredDtoRoutes[$action] = $dtoClass;
            }
        }

        foreach ($declaredRoutes as $action => $agentType) {
            if (($agentActionRoutes[$action] ?? null) !== $agentType) {
                $errors[] = "Agent action route {$action} is missing from computed agent action routes";
            }
            if (($agentActionDtoRoutes[$action] ?? null) !== $declaredDtoRoutes[$action]) {
                $errors[] = "Agent action DTO route {$action} is missing from computed agent action DTO routes";
            }
        }
    }

    /**
     * Validates the guard lists an agent declares over its own actions.
     *
     * THROTTLED_ACTIONS and AUTH_ACTIONS name actions of the declaring agent, so a name
     * that is not in its AGENT_ACTIONS guards nothing: the dispatcher looks the lists up
     * on whoever owns the action being dispatched, and an action owned elsewhere never
     * asks this agent. A typo there is silent - the action simply runs unguarded - which
     * is why it is refused at startup instead (HIL-622).
     *
     * @param array $agents Agent registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgentActionGuards(array $agents, array &$errors): void
    {
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            $guardLists = [
                'THROTTLED_ACTIONS' => $agentClass::THROTTLED_ACTIONS,
                'AUTH_ACTIONS' => $agentClass::AUTH_ACTIONS,
            ];
            foreach ($guardLists as $listName => $actions) {
                foreach ($actions as $action) {
                    if (!is_string($action) || $action === '') {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} {$listName} must list non-empty action names";
                        continue;
                    }

                    if (!array_key_exists($action, $agentClass::AGENT_ACTIONS)) {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} {$listName} names {$action}, "
                            . 'which it does not own through AGENT_ACTIONS';
                    }
                }
            }
        }
    }

    /**
     * Validates page-owned non-action signal route declarations.
     *
     * @param array $pages Page registry
     * @param array $pageSignalRoutes Computed page signal route registry
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
                        $errors[] = "PAGES[{$page}] class {$pageClass} SIGNALS[{$signalType}] must contain"
                            . ' only non-empty signal names or valid signal DTO map entries';
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
                        $errors[] = "Page signal {$signalType}/{$signalName} is declared by multiple pages:"
                            . " {$namedRoutes[$signalType][$signalName]} and {$page}";
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
     * @param array $pages Page registry
     * @param array $pageSignalDtoRoutes Computed page signal DTO route registry
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
     * @param array $agents Agent registry
     * @param array $agentSignalRoutes Computed agent signal route registry
     * @param array $pageSignalAgentRoutes Computed page signal owner agent routes
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
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS must contain"
                            . ' only non-empty signal names or valid indexed config arrays';
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
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS must contain only"
                        . ' non-empty signal names, valid signal DTO map entries, or valid indexed config arrays';
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
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] must be a"
                        . ' non-empty signal name, a SignalDataInterface class, or a valid indexed config array';
                    continue;
                }

                $unknownKeys = array_diff(array_keys($value), [AgentSignalConfigKey::INDEX_FIELD, AgentSignalConfigKey::DTO]);
                if ($unknownKeys !== []) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] contains"
                        . ' unknown config keys: ' . implode(', ', $unknownKeys);
                }

                $indexField = $value[AgentSignalConfigKey::INDEX_FIELD] ?? null;
                if (!is_string($indexField) || $indexField === '') {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] must declare"
                        . " a non-empty '" . AgentSignalConfigKey::INDEX_FIELD . "'";
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
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}]["
                            . AgentSignalConfigKey::DTO . "] class {$dtoClass} must implement "
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
     * @param array $agents Agent registry
     * @param array $agentSignalDtoRoutes Computed agent signal DTO route registry
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
     * Validates agent-owned CLI command route declarations.
     *
     * Checks AGENT_COMMANDS entry shape (list name, command => DTO class, or command
     * => config array), duplicate command ownership (one command = exactly one agent),
     * that any declared DTO class implements SignalDataInterface, that a declared test-only
     * flag is a bool, and that the flag and the `test:` name prefix agree in both directions.
     * Also verifies each declared route appears in the computed command route map.
     *
     * @param array $agents Agent registry
     * @param array $commandAgentRoutes Computed command route registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateAgentCommandRoutes(array $agents, array $commandAgentRoutes, array &$errors): void
    {
        $declaredRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_COMMANDS as $key => $value) {
                // List entry — int key + non-empty string command name.
                if (is_int($key)) {
                    if (!is_string($value) || $value === '') {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS must contain"
                            . ' only non-empty command names or valid command config entries';
                        continue;
                    }

                    $this->recordDeclaredCommandRoute($value, $agentType, $declaredRoutes, $errors);
                    $this->validateTestOnlyContract($value, false, $agentType, $agentClass, $errors);
                    continue;
                }

                if (!is_string($key) || $key === '') {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS must contain only"
                        . ' non-empty command names, command DTO map entries, or valid command config arrays';
                    continue;
                }

                // Map entry — command name key + DTO class-string value.
                if (is_string($value) && $value !== '') {
                    if ($this->isExistingClassString(
                        $value,
                        "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}]",
                        $errors,
                    ) && !is_subclass_of($value, SignalDataInterface::class)) {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}] class {$value} must implement "
                            . SignalDataInterface::class;
                    }

                    $this->recordDeclaredCommandRoute($key, $agentType, $declaredRoutes, $errors);
                    $this->validateTestOnlyContract($key, false, $agentType, $agentClass, $errors);
                    continue;
                }

                // Map entry — command name key + config array value.
                if (!is_array($value)) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}] must be a"
                        . ' non-empty command name, a SignalDataInterface class, or a valid command config array';
                    continue;
                }

                $unknownKeys = array_diff(
                    array_keys($value),
                    [AgentCommandConfigKey::DTO, AgentCommandConfigKey::TEST_ONLY],
                );
                if ($unknownKeys !== []) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}] contains"
                        . ' unknown config keys: ' . implode(', ', $unknownKeys);
                }

                $testOnly = $value[AgentCommandConfigKey::TEST_ONLY] ?? null;
                if ($testOnly !== null && !is_bool($testOnly)) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}]["
                        . AgentCommandConfigKey::TEST_ONLY . '] must be a bool';
                }

                $dtoClass = $value[AgentCommandConfigKey::DTO] ?? null;
                if ($dtoClass !== null) {
                    if ($this->isExistingClassString(
                        $dtoClass,
                        "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}][" . AgentCommandConfigKey::DTO . ']',
                        $errors,
                    ) && !is_subclass_of($dtoClass, SignalDataInterface::class)) {
                        $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}]["
                            . AgentCommandConfigKey::DTO . "] class {$dtoClass} must implement "
                            . SignalDataInterface::class;
                    }
                }

                $this->recordDeclaredCommandRoute($key, $agentType, $declaredRoutes, $errors);
                $this->validateTestOnlyContract($key, $testOnly === true, $agentType, $agentClass, $errors);
            }
        }

        foreach ($declaredRoutes as $command => $agentType) {
            if (($commandAgentRoutes[$command] ?? null) !== $agentType) {
                $errors[] = "Agent command route {$command} is missing from computed command routes";
            }
        }
    }

    /**
     * Sews the two halves of the test-only contract together, in both directions.
     *
     * The flag is what the socket gate reads; the `test:` prefix is what a person reads. Either
     * one alone is a hole, and a different one each way: a prefixed name without the flag looks
     * guarded to every reviewer and is wide open on a production node, while a flagged name
     * without the prefix is refused by a gate nobody reading the name would expect. Review
     * discipline has already failed this once - before HIL-566 six of the twelve test-only names
     * carried no prefix at all - which is why it is checked by the daemon's start instead.
     *
     * @param string $command Declared command name
     * @param bool $testOnly Whether the entry declares the test-only flag
     * @param string $agentType Declaring agent type
     * @param class-string<AbstractAgent> $agentClass Declaring agent class
     * @param list<string> $errors Validation error accumulator
     */
    private function validateTestOnlyContract(
        string $command,
        bool $testOnly,
        string $agentType,
        string $agentClass,
        array &$errors,
    ): void {
        $prefixed = str_starts_with($command, CommandConstants::TEST_ONLY_PREFIX);
        if ($prefixed && !$testOnly) {
            $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$command}] is named with the "
                . CommandConstants::TEST_ONLY_PREFIX . ' prefix but does not declare '
                . AgentCommandConfigKey::TEST_ONLY;
        }

        if (!$prefixed && $testOnly) {
            $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$command}] declares "
                . AgentCommandConfigKey::TEST_ONLY . ' but is not named with the '
                . CommandConstants::TEST_ONLY_PREFIX . ' prefix';
        }
    }

    /**
     * Records a declared command route, flagging duplicate command ownership.
     *
     * @param string $command Command name
     * @param string $agentType Declaring agent type
     * @param array<string, string> $declaredRoutes Command owner accumulator, keyed by command name
     * @param list<string> $errors Validation error accumulator
     */
    private function recordDeclaredCommandRoute(string $command, string $agentType, array &$declaredRoutes, array &$errors): void
    {
        if (isset($declaredRoutes[$command]) && $declaredRoutes[$command] !== $agentType) {
            $errors[] = "Command {$command} is declared by multiple agents: {$declaredRoutes[$command]} and {$agentType}";

            return;
        }

        $declaredRoutes[$command] = $agentType;
    }

    /**
     * Validates page source bindings against registered pages and sources.
     *
     * @param array $pages Page registry
     * @param array $tables Registered table registry
     * @param array $browserSources Merged browser source registry (lists, tables, and data)
     * @param array $pageTables Page binding registry (tables, lists, or data)
     * @param string $registry Page binding registry constant name for error messages
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageTables(
        array $pages,
        array $tables,
        array $browserSources,
        array $pageTables,
        string $registry,
        array &$errors,
    ): void {
        foreach ($pageTables as $page => $bindings) {
            if (!is_string($page)) {
                $errors[] = "{$registry} contains a non-string page key";
                continue;
            }

            if (!array_key_exists($page, $pages)) {
                $errors[] = "{$registry}[{$page}] references a page missing from PAGES";
            }

            if (!is_array($bindings)) {
                $errors[] = "{$registry}[{$page}] must be an array of source bindings";
                continue;
            }

            foreach ($bindings as $table => $config) {
                if (!is_string($table)) {
                    $errors[] = "{$registry}[{$page}] contains a non-string source key";
                    continue;
                }

                if (!array_key_exists($table, $tables) && !array_key_exists($table, $browserSources)) {
                    $errors[] = "{$registry}[{$page}][{$table}] references a source missing from TABLES,"
                        . ' BROWSER_LISTS, BROWSER_TABLES, and BROWSER_DATA';
                }

                if (!is_array($config)) {
                    $errors[] = "{$registry}[{$page}][{$table}] config must be an array";
                }
            }
        }
    }

    /**
     * Validates the protected-mode stub registry against the copy the maintenance surface needs.
     *
     * Judged at startup because there is no later moment to judge it in: the registry is read
     * while a node is frozen, and an entry that answers nothing turns into a maintenance screen
     * without words in the middle of a restore, where nobody is left to report it. The state is
     * judged, not the history - the framework default is subject to the same rule as an override.
     *
     * Read through {@see Hilos::catalogConstantOf()} rather than {@see constantArray()}: the
     * constant is protected, so `defined()` answers false from this scope, the registry would
     * read as an empty array, and the rule would refuse every project on startup over a default
     * entry the facade does declare.
     *
     * @param mixed $registry Stub registry declared by the facade
     * @param list<string> $errors Validation error accumulator
     */
    private function validateProtectedModeStub(mixed $registry, array &$errors): void
    {
        if (!is_array($registry)) {
            $errors[] = self::SECTION_PROTECTED_MODE_STUB . ' must be an array';
            return;
        }

        if (!array_key_exists(ProtectedModeStubConstants::DEFAULT_OPERATION, $registry)) {
            $errors[] = self::SECTION_PROTECTED_MODE_STUB . ' is missing the '
                . ProtectedModeStubConstants::DEFAULT_OPERATION . ' entry';
        }

        foreach ($registry as $operation => $entry) {
            if (!is_string($operation) || $operation === '') {
                $errors[] = self::SECTION_PROTECTED_MODE_STUB . ' contains a non-string or empty operation key';
                continue;
            }

            $this->validateProtectedModeStubEntry($operation, $entry, $errors);
        }
    }

    /**
     * Validates one stub registry entry: both copy fields as non-empty text, and nothing else.
     *
     * Empty strings and unknown fields are violations rather than shrugs because
     * {@see ProtectedModeStubCopy} cannot report either one: it turns a non-string into null and
     * hands an empty title to the surface as it stands, so a field misspelled `mesage` reaches
     * the user as a screen with a heading and no sentence.
     *
     * @param string $operation Operation name the entry is keyed by
     * @param mixed $entry Registry entry declared for that operation
     * @param list<string> $errors Validation error accumulator
     */
    private function validateProtectedModeStubEntry(string $operation, mixed $entry, array &$errors): void
    {
        $path = self::SECTION_PROTECTED_MODE_STUB . "[{$operation}]";
        $copyFields = [ProtectedModeStubConstants::TITLE, ProtectedModeStubConstants::MESSAGE];
        if (!is_array($entry)) {
            $errors[] = "{$path} must be an array carrying " . implode(' and ', $copyFields);
            return;
        }

        foreach ($copyFields as $field) {
            $value = $entry[$field] ?? null;
            if (!is_string($value) || $value === '') {
                $errors[] = "{$path}[{$field}] must be a non-empty string";
            }
        }

        $unknownFields = array_diff(array_keys($entry), $copyFields);
        if ($unknownFields !== []) {
            $errors[] = "{$path} contains unknown entry fields: " . implode(', ', $unknownFields);
        }
    }

    /**
     * Validates the merged admin page catalog: every caption present, every parent and every
     * dashboard item naming an entry, and no cycle in the tree.
     *
     * Judged here, at daemon start, rather than survived at runtime. The readers walk the tree
     * without a net on purpose: a breadcrumb that skips a broken link, or a dashboard that drops
     * a card it cannot name, hides the typo for as long as nobody reads the catalog next to the
     * screen. Refusing to start says it once, immediately, with the key in the message.
     *
     * The registration of a page in PAGES is deliberately NOT required: a card pointing at a
     * feature the project has not activated stays visible, which is what the frontend does today
     * and what hiding-by-feature would have to change on purpose.
     *
     * Read through {@see Hilos::catalogConstantOf()} for the reason
     * {@see validateProtectedModeStub()} is: the constant is protected, and `defined()` answers
     * false from this scope.
     *
     * @param mixed $provider Page catalog provider class declared by the facade
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageCatalog(mixed $provider, array &$errors): void
    {
        if (!$this->isExistingClassString($provider, self::SECTION_PAGE_CATALOG, $errors)) {
            return;
        }

        if (!is_subclass_of($provider, PageCatalogProviderInterface::class)) {
            $errors[] = self::SECTION_PAGE_CATALOG . " class {$provider} must implement "
                . PageCatalogProviderInterface::class;
            return;
        }

        /** @var class-string<PageCatalogProviderInterface> $provider */
        $catalog = PageCatalogResolver::catalogOf($provider);
        foreach ($catalog as $page => $entry) {
            $this->validatePageCatalogEntry($catalog, (string)$page, $entry, $errors);
        }

        foreach (PageCatalogResolver::dashboardSectionsOf($provider) as $index => $section) {
            $this->validatePageCatalogSection($catalog, $index, $section, $errors);
        }
    }

    /**
     * Validates one catalog entry: both its texts, its parent, and its way up to the root.
     *
     * The caption and the lead are judged together because both are read unconditionally when a
     * page answers its subscription: an entry missing either one would pass startup and then warn
     * on every subscription while putting a null caption on the wire.
     *
     * @param array<string, mixed> $catalog Merged page catalog
     * @param string $page Page key the entry is filed under
     * @param mixed $entry Catalog entry declared for that page
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageCatalogEntry(array $catalog, string $page, mixed $entry, array &$errors): void
    {
        $path = self::SECTION_PAGE_CATALOG . "[{$page}]";
        $texts = [PageCatalogConstants::CATALOG_ENTRY_LABEL, PageCatalogConstants::CATALOG_ENTRY_LEAD];
        if (!is_array($entry)) {
            $errors[] = "{$path} must be an array carrying " . implode(' and ', $texts);
            return;
        }

        foreach ($texts as $field) {
            $value = $entry[$field] ?? null;
            if (!is_string($value) || $value === '') {
                $errors[] = "{$path}[{$field}] must be a non-empty string";
            }
        }

        $parent = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
        if ($parent === null) {
            return;
        }

        if (!is_string($parent) || !array_key_exists($parent, $catalog)) {
            $errors[] = "{$path}[" . PageCatalogConstants::CATALOG_ENTRY_PARENT . '] names no catalog entry';
            return;
        }

        if ($this->pageCatalogWalkLoops($catalog, $page)) {
            $errors[] = "{$path} sits in a parent cycle and never reaches the tree root";
        }
    }

    /**
     * Validates one dashboard section: both its texts, and every item it lists carrying an entry.
     *
     * The texts are judged for the reason the entry's are in {@see validatePageCatalogEntry()}:
     * the dashboard reads them unconditionally when it builds its cards.
     *
     * @param array<string, mixed> $catalog Merged page catalog
     * @param mixed $index Position of the section in the merged list
     * @param mixed $section Section declared at that position
     * @param list<string> $errors Validation error accumulator
     */
    private function validatePageCatalogSection(array $catalog, mixed $index, mixed $section, array &$errors): void
    {
        $path = self::SECTION_PAGE_CATALOG . ' dashboard section ' . (is_int($index) ? (string)$index : '?');
        if (!is_array($section)) {
            $errors[] = "{$path} must be an array";
            return;
        }

        foreach ([PageCatalogConstants::SECTION_TITLE, PageCatalogConstants::SECTION_DESCRIPTION] as $field) {
            $value = $section[$field] ?? null;
            if (!is_string($value) || $value === '') {
                $errors[] = "{$path}[{$field}] must be a non-empty string";
            }
        }

        $items = $section[PageCatalogConstants::SECTION_ITEMS] ?? null;
        if (!is_array($items)) {
            $errors[] = "{$path} must carry an array of " . PageCatalogConstants::SECTION_ITEMS;
            return;
        }

        foreach ($items as $page) {
            if (!is_string($page) || !array_key_exists($page, $catalog)) {
                $errors[] = "{$path} lists an item with no catalog entry";
            }
        }
    }

    /**
     * Reports whether walking `parent` up from a page revisits a key instead of reaching a root.
     *
     * @param array<string, mixed> $catalog Merged page catalog
     * @param string $page Page key to walk up from
     * @return bool True when the walk meets a key it already passed
     */
    private function pageCatalogWalkLoops(array $catalog, string $page): bool
    {
        $seen = [$page => true];
        $key = $page;
        while (true) {
            $entry = $catalog[$key] ?? null;
            $key = is_array($entry) ? ($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) : null;
            if (!is_string($key) || !array_key_exists($key, $catalog)) {
                return false;
            }

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
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
