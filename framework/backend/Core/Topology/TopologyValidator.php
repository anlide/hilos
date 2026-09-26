<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Closure;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Constants\HilosSignalConstants;
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
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceKind;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\Config\GroupAddressSource;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceOwner;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\Database\Schema\SetTree;
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

    private const string SECTION_SHARED_DB_OWNERS = 'SHARED_DB_OWNERS';

    private const string SECTION_SHARED_RT_OWNERS = 'SHARED_RT_OWNERS';

    /**
     * The layer a shared-ownership refusal names a collection by. Two constants for two words
     * that only look alike: a db collection and an rt collection of the same name are different
     * sets of rows, and the halves are judged apart for that reason.
     */
    private const string HALF_DB = 'db';

    private const string HALF_RT = 'rt';

    /**
     * Claim widths compared by the ownership-pair judge, from narrowest to widest.
     */
    private const string CLAIM_WHOLE = 'whole';

    private const string CLAIM_SET = 'set';

    private const string CLAIM_ROWS = 'rows';

    private const array CLAIM_WIDTHS = [self::CLAIM_ROWS, self::CLAIM_SET, self::CLAIM_WHOLE];

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
        $this->validateDeclaredOwnership($agents, $hilosClass, $errors);
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
        $this->validateThrottleVerdictRoutes(
            $agents,
            $pages,
            $hilosClass::getAgentSignalRoutes(),
            $hilosClass::getAgentSignalDtoRoutes(),
            $hilosClass::getAgentSignalIndexFields(),
            $errors,
        );
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
     * @throws InvalidTopologyException When a declaration names a collection no layer mounts, or an agent claims a set of a table cut by no column
     * @throws InvalidArgumentException When an index declaration names a direction or a type it cannot name
     */
    public function validateReferences(string $hilosClass): void
    {
        $errors = [];
        $declarations = [];
        $joins = [];
        foreach ([self::SECTION_BROWSER_TABLES, self::SECTION_BROWSER_LISTS, self::SECTION_BROWSER_DATA] as $registry) {
            $this->collectBrowserSourceReferences(
                $this->constantArray($hilosClass, $registry, $errors),
                $registry,
                $declarations,
                $joins,
            );
        }

        $this->collectPageGuardReferences($this->constantArray($hilosClass, 'PAGES', $errors), $declarations);

        foreach ($declarations as $identity => $path) {
            [$type, $name] = explode(':', $identity, 2);
            if ($type === BrowserSourceType::DB) {
                if (Hilos::$db?->mountedObjectCollection($name) === null) {
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

        $this->validateBrowserJoinColumns($joins, $errors);
        $this->validateSetClaims($this->constantArray($hilosClass, 'AGENTS', $errors), $errors);

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors($hilosClass, $errors);
        }
    }

    /**
     * Holds every declared database join against the child table's own keys and indexes.
     *
     * A join is read by asking the table for one column's value, so a column the table cannot be
     * searched by turns the row's silence into a full scan of it - the same defect at a different
     * price. Judged here rather than in {@see self::validate()} because the answer lives on the
     * mounted collection's entity, and nothing is mounted that early.
     *
     * @param list<array{registry: string, browserKey: string, sourceKey: string, column: string}> $joins
     *     Declared database joins, in declaration order
     * @param list<string> $errors Validation error accumulator
     * @throws InvalidArgumentException When an index declaration names a direction or a type it cannot name
     */
    private function validateBrowserJoinColumns(array $joins, array &$errors): void
    {
        foreach ($joins as $join) {
            $collection = Hilos::$db?->mountedObjectCollection($join['sourceKey']);
            if ($collection === null) {
                // An unmounted collection is already reported above, and by its own name.
                continue;
            }
            if ($collection->isKeyColumn($join['column']) || $collection->isIndexLeadColumn($join['column'])) {
                continue;
            }

            $errors[] = "{$join['registry']}[{$join['browserKey']}]: join column '{$join['column']}'"
                . " of source '{$join['sourceKey']}' is neither the primary key"
                . " nor the leftmost column of an index that can answer a lookup by value";
        }
    }

    /**
     * Holds every claim over a set against the Entity of the collection it names.
     *
     * A set is the rows one column cuts out of a table, and a table whose Entity declares
     * `Entity::SET_STANDALONE` is cut by no column: a claim over a set of it holds nothing, and
     * would say so only when the first write of the owner is refused. A table whose set column
     * points at a table in a set of its own is claimed by the key at the top of that tree, and the
     * claim is refused when a table of the climb is not mounted or is one the agent neither reads
     * nor claims ({@see self::validateSetClimb()}). Judged here rather than in
     * {@see self::validate()} for the reason {@see self::validateBrowserJoinColumns()} is: the
     * answer lives on the mounted collection's Entity, and nothing is mounted that early. The start
     * of the agent cannot judge it either - what it reads is the class and its instance, not the
     * table.
     *
     * Silent in three cases, and in each the refusal stands elsewhere or is not owed. A collection
     * that is not mounted: no claim is held against the mounting today, whatever its width. A
     * mounted collection with no Entity behind it: a broken mount, which {@see SetOwnershipGuard}
     * passes over too. An Entity that declares no `_setVia` at all: {@see SetOwnershipGuard} has
     * refused the node before the first agent was built ({@see DaemonApplication::run()}). Nor does
     * it repeat that guard's cross-check of the root a column points at - a claim is judged here,
     * not a table.
     *
     * @param array $agents Agent registry
     * @param list<string> $errors Validation error accumulator
     */
    private function validateSetClaims(array $agents, array &$errors): void
    {
        // A class the registry names wrongly is refused by validate(), which already ran.
        $rejected = [];
        foreach ($this->declaredOwnerClasses($agents, $rejected) as $ownerClass) {
            foreach (array_keys(OwnershipDeclaration::dbSetCollectionsOf($ownerClass)) as $collection) {
                $mounted = Hilos::$db?->mountedObjectCollection($collection);
                if ($mounted === null) {
                    continue;
                }

                $objectClass = $mounted::OBJECT_CLASS;
                if (!is_subclass_of($objectClass, Object_::class)) {
                    continue;
                }

                $entityClass = $objectClass::ENTITY_CLASS;
                if (!is_subclass_of($entityClass, Entity::class) || !defined("{$entityClass}::" . Entity::META_SET_VIA)) {
                    continue;
                }

                if (constant("{$entityClass}::" . Entity::META_SET_VIA) === Entity::SET_STANDALONE) {
                    $errors[] = "{$ownerClass} claims db collection '{$collection}' by a set, but its Entity {$entityClass}"
                        . " declares _setVia Entity::SET_STANDALONE: a table cut by no column has no set to claim";

                    continue;
                }

                $this->validateSetClimb($ownerClass, $collection, $entityClass, $errors);
            }
        }
    }

    /**
     * Holds one claim over a set against the tables its set tree climbs through.
     *
     * A write under the claim reaches the top of the tree by reading each parent row through the
     * guarded entrance ({@see SetTree::topOfSetKey()}), so every table of the climb has to be one
     * the agent reads or holds - otherwise its first write is refused by the read guard instead of
     * its start. A table of the climb that is not mounted leaves no row of the claimed table able to
     * reach the top. One rule for tables with a short path and without: the statement over one set
     * climbs the value of the set column there too.
     *
     * @param class-string<AbstractAgent> $ownerClass Class that claims the set
     * @param string $collection Collection the claim names
     * @param class-string<Entity> $entityClass Entity of that collection
     * @param list<string> $errors Validation error accumulator
     */
    private function validateSetClimb(string $ownerClass, string $collection, string $entityClass, array &$errors): void
    {
        $reachable = [
            ...$ownerClass::READS_DB,
            ...array_keys(OwnershipDeclaration::dbCollectionsOf($ownerClass)),
            ...array_keys(OwnershipDeclaration::dbRowCollectionsOf($ownerClass)),
            ...array_keys(OwnershipDeclaration::dbSetCollectionsOf($ownerClass)),
        ];

        foreach (SetTree::walkOf($entityClass) as $table => $parent) {
            if ($parent === null) {
                $errors[] = "{$ownerClass} claims db collection '{$collection}' by a set, and the set tree of {$entityClass}"
                    . " climbs through table '{$table}', which is not mounted: no row of it can be walked to the top";

                return;
            }

            if (!in_array($parent, $reachable, true)) {
                $errors[] = "{$ownerClass} claims db collection '{$collection}' by a set, and the set tree of {$entityClass}"
                    . " climbs through db collection '{$parent}', which it neither reads nor claims";
            }
        }
    }

    /**
     * Collects the source declarations one browser registry's rows reference.
     *
     * @param array $browserSources Browser source registry (tables, lists, or data)
     * @param string $registry Registry constant name for error messages
     * @param array<string, string> $declarations Identity-to-path accumulator
     * @param list<array{registry: string, browserKey: string, sourceKey: string, column: string}> $joins
     *     Declared database joins accumulator
     */
    private function collectBrowserSourceReferences(
        array $browserSources,
        string $registry,
        array &$declarations,
        array &$joins,
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
                if (!is_array($row)) {
                    continue;
                }

                $source = $row[BrowserFieldKey::SOURCE] ?? null;
                $this->rememberBrowserSourceReference($source, "{$path} {$rowsKey}[{$index}]", $declarations);
                $this->rememberBrowserJoinColumn($source, $row, $registry, $key, $joins);
            }
        }
    }

    /**
     * Remembers the column one database row config joins by, for the index rule to hold it to.
     *
     * @param mixed $source Declared source entry
     * @param array<string, mixed> $rowConfig Browser row source config
     * @param string $registry Registry constant name for error messages
     * @param string $browserKey Browser source key for error messages
     * @param list<array{registry: string, browserKey: string, sourceKey: string, column: string}> $joins
     *     Declared database joins accumulator
     */
    private function rememberBrowserJoinColumn(
        mixed $source,
        array $rowConfig,
        string $registry,
        string $browserKey,
        array &$joins,
    ): void {
        if (!is_array($source) || ($source[BrowserSourceKey::TYPE] ?? null) !== BrowserSourceType::DB) {
            return;
        }

        $sourceKey = $source[BrowserSourceKey::KEY] ?? null;
        [$column] = BrowserSourceConfig::joinBy($rowConfig);
        if (!is_string($sourceKey) || $column === null) {
            return;
        }

        $joins[] = [
            'registry' => $registry,
            'browserKey' => $browserKey,
            'sourceKey' => $sourceKey,
            'column' => $column,
        ];
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

            // A declared name carries no param: the param travels after a colon on the wire, and
            // a registered name holding one would be resolvable two ways at once.
            if (str_contains($group, ':')) {
                $errors[] = "GROUPS[{$group}] name must carry no ':' - the param is appended on the wire";
            }

            if ($groupClass::ADDRESS === GroupAddressSource::SESSION) {
                $errors[] = "GROUPS[{$group}] is addressed by session, which no node serves yet (HIL-111)";
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

            $idleTimeout = $registryEntry[AgentRegistryKey::IDLE_TIMEOUT] ?? null;
            if ($idleTimeout !== null && (!is_int($idleTimeout) || $idleTimeout <= 0)) {
                $errors[] = 'AGENTS[' . $agentType . '][' . AgentRegistryKey::IDLE_TIMEOUT
                    . '] must be a positive integer number of seconds';
            } elseif ($idleTimeout !== null && $indexed !== true) {
                $errors[] = 'AGENTS[' . $agentType . '] cannot set ' . AgentRegistryKey::IDLE_TIMEOUT
                    . ' without ' . AgentRegistryKey::INDEXED
                    . ': an idle window is declared on an instance agent, and only an addressed'
                    . ' frame brings one back';
            }
        }
    }

    /**
     * Refuses a topology whose declared ownership contradicts itself, before anything starts.
     *
     * Four contradictions, all of them readable off the classes: two owners holding one
     * collection in full; one owner holding a collection whole while another holds rows or a set
     * of it; two different classes holding sets of one table in full; and a class naming one
     * collection in both its reads and its claims. A pair of by-row claims and a pair of set and
     * by-row claims are left alone, because only the instance and database know which rows an
     * instance holds and whether one of those rows belongs to the set. What those pairs do at
     * runtime stays with the runtime guard, which judges by the claim actually laid down. Set
     * owners are compared by class rather than by key: one column cuts the table and the two
     * classes meet on every instance for which both are started, while instances of one class
     * hold different sets.
     *
     * Full is the word the runtime already uses ({@see TruthSourceOperations::isComplete()}): a
     * collection has one full owner, and a co-owner short of an operation beside it is a declared
     * shape and not a collision - a notification library against its delivery channels, a set
     * holder against the library that parks what it just created. A start-time refusal judging
     * that any other way would refuse what the running system calls normal.
     *
     * What it deliberately does NOT answer is whether a collection has an owner at all. Seven
     * live claims in this codebase come from classes that are not agents - six test-only commands
     * and the application class - and a topology cannot see them: their claim is laid down and
     * taken back by the runner of the command that made it. A rule promising that check would be
     * a rule lying about its own reach.
     *
     * @param array $agents Agent registry
     * @param class-string<Hilos> $hilosClass Project facade class
     * @param list<string> $errors Validation error accumulator
     */
    private function validateDeclaredOwnership(array $agents, string $hilosClass, array &$errors): void
    {
        $rejected = [];
        $owners = $this->declaredOwnerClasses($agents, $rejected);

        $this->validateSharedOwners(
            $this->ownershipConflicts($this->completeClaims(
                $owners,
                [
                    self::CLAIM_WHOLE => OwnershipDeclaration::dbCollectionsOf(...),
                    self::CLAIM_SET => OwnershipDeclaration::dbSetCollectionsOf(...),
                    self::CLAIM_ROWS => OwnershipDeclaration::dbRowCollectionsOf(...),
                ],
            )),
            $this->constantArray($hilosClass, self::SECTION_SHARED_DB_OWNERS, $errors),
            self::SECTION_SHARED_DB_OWNERS,
            self::HALF_DB,
            $owners,
            $rejected,
            $errors,
        );
        $this->validateSharedOwners(
            $this->ownershipConflicts($this->completeClaims(
                $owners,
                [
                    self::CLAIM_WHOLE => OwnershipDeclaration::rtCollectionsOf(...),
                    self::CLAIM_ROWS => OwnershipDeclaration::rtRowCollectionsOf(...),
                ],
            )),
            $this->constantArray($hilosClass, self::SECTION_SHARED_RT_OWNERS, $errors),
            self::SECTION_SHARED_RT_OWNERS,
            self::HALF_RT,
            $owners,
            $rejected,
            $errors,
        );

        $this->validateClaimedReads($owners, $errors);
    }

    /**
     * Collects the agent classes whose declarations this rule reads.
     *
     * Deduplicated by class-string, because the registry names runtimes and not owners: one class
     * registered under two types, a sharded pool and an every-node replica all declare the same
     * ownership once, and a class cannot collide with itself.
     *
     * @param array $agents Agent registry
     * @param list<string> $rejected Classes {@see self::validateAgents()} already refused, collected for the caller
     * @return list<class-string<AbstractAgent>> Worker classes the registry names, each of them once
     */
    private function declaredOwnerClasses(array $agents, array &$rejected): array
    {
        $owners = [];
        foreach ($agents as $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            if ($workerClass === null) {
                continue;
            }

            if (!class_exists($workerClass) || !is_subclass_of($workerClass, AbstractAgent::class)) {
                $rejected[] = $workerClass;
                continue;
            }

            if (!in_array($workerClass, $owners, true)) {
                $owners[] = $workerClass;
            }
        }

        return $owners;
    }

    /**
     * Reads one half of the declarations and keeps the claims that are full by operation.
     *
     * The declarations are not parsed here: the readers of {@see OwnershipDeclaration} fold
     * the inheritance chain and expand {@see TruthSourceOperation::BY_KIND} already, and a second
     * reading of one declaration would drift away from the first silently, in the rule that
     * refuses a start.
     *
     * A class naming one collection in more than one width is read at its widest. That group of
     * declarations is a contradiction the claim itself refuses at start
     * ({@see OwnershipDeclaration::claimDbRows()}, {@see OwnershipDeclaration::claimDbSet()});
     * until then the widest is what this rule judges, so the refusal it prints is the one with
     * the largest reach.
     *
     * @param list<class-string<AbstractAgent>> $ownerClasses Classes to read the declarations off
     * @param array<string, Closure(class-string<AbstractAgent>): array<string, TruthSourceOperations>> $readers
     *     Claim width => reader of the claims of that width
     * @return array<string, array<class-string<AbstractAgent>, string>> Collection => owner => width of its full claim
     */
    private function completeClaims(array $ownerClasses, array $readers): array
    {
        $claims = [];
        foreach ($ownerClasses as $ownerClass) {
            foreach (self::CLAIM_WIDTHS as $width) {
                if (!isset($readers[$width])) {
                    continue;
                }

                foreach ($readers[$width]($ownerClass) as $collection => $operations) {
                    if ($operations->isComplete()) {
                        $claims[$collection][$ownerClass] = $width;
                    }
                }
            }
        }

        return $claims;
    }

    /**
     * Pairs up the claims of one half that cannot both stand.
     *
     * A pair collides when at least one claim covers the whole collection or both cover sets.
     * Two by-row claims and a set-plus-row pair are not judged here. A whole-collection owner is
     * named first, which is the order its refusal reads in.
     *
     * @param array<string, array<class-string<AbstractAgent>, string>> $claims Collection => owner => claim width
     * @return array<string, list<array{first: class-string<AbstractAgent>, second: class-string<AbstractAgent>,
     *     firstWidth: string, secondWidth: string}>> Collection => colliding pairs
     */
    private function ownershipConflicts(array $claims): array
    {
        $conflicts = [];
        foreach ($claims as $collection => $owners) {
            $classes = array_keys($owners);
            $count = count($classes);
            for ($first = 0; $first < $count; $first++) {
                for ($second = $first + 1; $second < $count; $second++) {
                    $left = $classes[$first];
                    $right = $classes[$second];
                    $leftWidth = $owners[$left];
                    $rightWidth = $owners[$right];
                    $anyWhole = $leftWidth === self::CLAIM_WHOLE || $rightWidth === self::CLAIM_WHOLE;
                    $bothSets = $leftWidth === self::CLAIM_SET && $rightWidth === self::CLAIM_SET;
                    if (!$anyWhole && !$bothSets) {
                        continue;
                    }

                    $conflicts[$collection][] = $rightWidth === self::CLAIM_WHOLE
                        && $leftWidth !== self::CLAIM_WHOLE
                        ? [
                            'first' => $right,
                            'second' => $left,
                            'firstWidth' => $rightWidth,
                            'secondWidth' => $leftWidth,
                        ]
                        : [
                            'first' => $left,
                            'second' => $right,
                            'firstWidth' => $leftWidth,
                            'secondWidth' => $rightWidth,
                        ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param array{first: class-string<AbstractAgent>, second: class-string<AbstractAgent>, firstWidth: string,
     *     secondWidth: string} $pair Colliding owner pair
     * @param string $half Layer the message names the collection by
     * @param string $collection Collection both agents claim
     * @return string Collision description without the shared-owner receipt instruction
     */
    private function collisionMessage(array $pair, string $half, string $collection): string
    {
        if ($pair['firstWidth'] === self::CLAIM_WHOLE && $pair['secondWidth'] === self::CLAIM_WHOLE) {
            return "{$pair['first']} and {$pair['second']} both own {$half} collection '{$collection}' in full";
        }

        if ($pair['firstWidth'] === self::CLAIM_WHOLE && $pair['secondWidth'] === self::CLAIM_ROWS) {
            return "{$pair['first']} owns {$half} collection '{$collection}' in full while {$pair['second']} owns rows of it";
        }

        if ($pair['firstWidth'] === self::CLAIM_WHOLE && $pair['secondWidth'] === self::CLAIM_SET) {
            return "{$pair['first']} owns {$half} collection '{$collection}' in full while {$pair['second']} owns a set of it";
        }

        return "{$pair['first']} and {$pair['second']} both own sets of {$half} collection '{$collection}' in full:"
            . ' one column cuts the table, so they meet on every instance both answer for';
    }

    /**
     * Judges the colliding pairs of one half against the receipts the project wrote for them.
     *
     * The list is a debt under lock, so it is read in both directions: a pair no row covers
     * refuses the start, and a row whose owners no longer collide refuses it too - parting them
     * for real takes the receipt away in the same commit, and a list describing the code of two
     * months ago is worth nothing to the person reading it.
     *
     * Nothing here stops a hand from writing one more row, and the machine is not asked to: what
     * holds the length is the topology snapshot of the project, red on any addition and silent on
     * a removal. Asserting the exact contents instead would paint a test red on every PARTING -
     * penalizing the one move the list exists to bring about.
     *
     * @param array<string, list<array{first: class-string<AbstractAgent>, second: class-string<AbstractAgent>,
     *     firstWidth: string, secondWidth: string}>> $conflicts Collection => colliding pairs
     * @param array $records Shared-owners registry of this half
     * @param string $section Registry constant name for error messages
     * @param string $half Layer the messages name the collection by
     * @param list<class-string<AbstractAgent>> $ownerClasses Worker classes the registry names
     * @param list<string> $rejectedClasses Classes {@see self::validateAgents()} already refused
     * @param list<string> $errors Validation error accumulator
     */
    private function validateSharedOwners(
        array $conflicts,
        array $records,
        string $section,
        string $half,
        array $ownerClasses,
        array $rejectedClasses,
        array &$errors,
    ): void {
        $recorded = [];
        foreach ($records as $collection => $record) {
            $recorded[$collection] = $this->sharedOwnerNames($record);
        }

        foreach ($conflicts as $collection => $pairs) {
            $names = $recorded[$collection] ?? [];
            foreach ($pairs as $pair) {
                if (in_array($pair['first'], $names, true) && in_array($pair['second'], $names, true)) {
                    continue;
                }

                $errors[] = $this->collisionMessage($pair, $half, $collection)
                    . "; narrow the operations of one claim, or list the pair in {$section}"
                    . ' with the leaf that parts them';
            }
        }

        foreach ($records as $collection => $record) {
            if (!is_string($collection)) {
                $errors[] = "{$section} contains a non-string collection key";
                continue;
            }

            $names = $recorded[$collection];
            if (array_intersect($names, $rejectedClasses) !== []) {
                continue;
            }

            $debt = is_array($record) ? ($record[SharedOwnersKey::DEBT] ?? null) : null;
            if (!is_string($debt) || $debt === '') {
                $errors[] = "{$section}['{$collection}'] has no debt: name the leaf that will part these owners";
            }

            if (count($names) < 2 || count(array_unique($names)) !== count($names)) {
                $errors[] = "{$section}['{$collection}'] must name at least two distinct owner classes";
                continue;
            }

            $unregistered = array_diff($names, $ownerClasses);
            foreach ($unregistered as $ownerClass) {
                $errors[] = "{$section}['{$collection}'] names {$ownerClass}, which is not registered in AGENTS";
            }

            if ($unregistered !== []) {
                continue;
            }

            $pairs = $conflicts[$collection] ?? [];
            if (!$this->namesACollidingPair($names, $pairs)) {
                $errors[] = "{$section}['{$collection}'] lists no pair that still conflicts;"
                    . ' the claims were parted, so the record goes with them';
                continue;
            }

            foreach ($names as $ownerClass) {
                if (!$this->collidesOverCollection($ownerClass, $pairs)) {
                    $errors[] = "{$section}['{$collection}'] names {$ownerClass},"
                        . ' which conflicts with nobody over that collection';
                }
            }
        }
    }

    /**
     * Reads the owner classes one shared-owners row spells.
     *
     * Answers a broken row with no names rather than an error of its own: a row that names
     * nothing covers no pair, and the refusals of the caller say both things already.
     *
     * @param mixed $record One row of a shared-owners registry
     * @return list<string> Owner class names the row spells, in the order it names them
     */
    private function sharedOwnerNames(mixed $record): array
    {
        if (!is_array($record)) {
            return [];
        }

        $names = $record[SharedOwnersKey::OWNERS] ?? [];

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }

    /**
     * @param list<string> $names Owner classes a shared-owners row names
     * @param list<array{first: class-string<AbstractAgent>, second: class-string<AbstractAgent>, firstWidth: string,
     *     secondWidth: string}> $pairs Colliding pairs over that collection
     * @return bool True when two of the named classes still collide
     */
    private function namesACollidingPair(array $names, array $pairs): bool
    {
        foreach ($pairs as $pair) {
            if (in_array($pair['first'], $names, true) && in_array($pair['second'], $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $ownerClass Owner class a shared-owners row names
     * @param list<array{first: class-string<AbstractAgent>, second: class-string<AbstractAgent>, firstWidth: string,
     *     secondWidth: string}> $pairs Colliding pairs over that collection
     * @return bool True when the class takes part in one of them
     */
    private function collidesOverCollection(string $ownerClass, array $pairs): bool
    {
        foreach ($pairs as $pair) {
            if ($pair['first'] === $ownerClass || $pair['second'] === $ownerClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuses a class that names one collection both as a reader and as an owner.
     *
     * Forbidden in words by both declarations already ({@see AbstractAgent::READS_DB},
     * {@see TruthSourceOwner::OWNS_DB}) and by one reason: a claim is the reader interest
     * already, so the second list only says the same thing in a form that can drift. No list of
     * exceptions stands beside this one - there is nothing to cover, and an empty way out is an
     * invitation to use it.
     *
     * @param list<class-string<AbstractAgent>> $ownerClasses Classes to read the declarations off
     * @param list<string> $errors Validation error accumulator
     */
    private function validateClaimedReads(array $ownerClasses, array &$errors): void
    {
        foreach ($ownerClasses as $ownerClass) {
            $this->refuseReadingOwnClaim(
                $ownerClass,
                $ownerClass::READS_DB,
                self::HALF_DB,
                'READS_DB',
                [
                    'OWNS_DB' => OwnershipDeclaration::dbCollectionsOf($ownerClass),
                    'OWNS_DB_ROWS' => OwnershipDeclaration::dbRowCollectionsOf($ownerClass),
                    'OWNS_DB_SET' => OwnershipDeclaration::dbSetCollectionsOf($ownerClass),
                ],
                $errors,
            );
            $this->refuseReadingOwnClaim(
                $ownerClass,
                $ownerClass::READS_RT,
                self::HALF_RT,
                'READS_RT',
                [
                    'OWNS_RT' => OwnershipDeclaration::rtCollectionsOf($ownerClass),
                    'OWNS_RT_ROWS' => OwnershipDeclaration::rtRowCollectionsOf($ownerClass),
                ],
                $errors,
            );
        }
    }

    /**
     * @param class-string<AbstractAgent> $ownerClass Class whose declarations are read
     * @param array $reads Collections the class declares it reads
     * @param string $half Layer the message names the collection by
     * @param string $readsConstant Name of the reader declaration, as the message spells it
     * @param array<string, array<string, TruthSourceOperations>> $claims Claim declaration name => collections it holds
     * @param list<string> $errors Validation error accumulator
     */
    private function refuseReadingOwnClaim(
        string $ownerClass,
        array $reads,
        string $half,
        string $readsConstant,
        array $claims,
        array &$errors,
    ): void {
        foreach ($reads as $collection) {
            if (!is_string($collection)) {
                continue;
            }

            foreach ($claims as $ownsConstant => $held) {
                if (array_key_exists($collection, $held)) {
                    $errors[] = "{$ownerClass} names {$half} collection '{$collection}' in both {$readsConstant}"
                        . " and {$ownsConstant}; a claim is the reader interest already";
                }
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

            $params = $browser[BrowserConfigKey::PARAMS] ?? [];
            $this->validateBrowserParamDeclarations($params, $path, $errors);

            $guards = $browser[BrowserConfigKey::GUARDS] ?? [];
            if (!is_array($guards)) {
                $errors[] = "{$path} guards must be a list of guard declarations";
                continue;
            }

            foreach ($guards as $index => $guard) {
                $this->validateBrowserGuard(
                    $guard,
                    "{$path} guards[{$index}]",
                    is_array($params) ? $params : [],
                    $errors,
                );
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
     * @param array<array-key, mixed> $pageParams Param declarations of the page that owns this guard
     * @param list<string> $errors Validation error accumulator
     */
    private function validateBrowserGuard(mixed $guard, string $path, array $pageParams, array &$errors): void
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

        $keyPath = "{$path} " . BrowserGuardKey::KEY;
        $key = $guard[BrowserGuardKey::KEY] ?? null;
        $this->validateBrowserRef($key, $keyPath, [], array_keys($pageParams), $errors);
        $this->validateGuardKeyIsRequiredParam($key, $keyPath, $pageParams, $errors);

        $knownErrors = [BrowserSubscriptionError::NOT_FOUND, BrowserSubscriptionError::FORBIDDEN];
        if (array_key_exists(BrowserGuardKey::ERROR, $guard)
            && !in_array($guard[BrowserGuardKey::ERROR], $knownErrors, true)) {
            $errors[] = "{$path} " . BrowserGuardKey::ERROR . ' must be one of ' . implode(', ', $knownErrors);
        }
    }

    /**
     * Refuses a guard key that names a param this page does not require.
     *
     * A guard whose key resolves to nothing is skipped whole at runtime
     * ({@see BrowserContext::assertDbExistsGuard()} returns on a null key), so a key naming a
     * param the page never declared - or declared as optional and simply never sent - reads as a
     * guarded page and serves an unguarded one. Both cases move to startup here, where the
     * difference is a refused topology instead of a silently open door. A key that is not a
     * page param (an `accept_key` ref) always resolves and is left alone.
     *
     * @param mixed $ref Declared guard key reference
     * @param string $path Topology path of the key itself
     * @param array<array-key, mixed> $pageParams Param declarations of the page that owns this guard
     * @param list<string> $errors Validation error accumulator
     */
    private function validateGuardKeyIsRequiredParam(
        mixed $ref,
        string $path,
        array $pageParams,
        array &$errors,
    ): void {
        if (!is_array($ref) || ($ref[BrowserRefKey::TYPE] ?? null) !== BrowserRefType::PAGE_PARAM) {
            return;
        }

        $name = $ref[BrowserRefKey::KEY] ?? null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $declaration = $pageParams[$name] ?? null;
        $required = is_array($declaration) ? ($declaration[BrowserParamKey::REQUIRED] ?? false) : false;
        if ($required !== true) {
            $errors[] = "{$path} " . BrowserRefType::PAGE_PARAM . " {$name} must be declared "
                . BrowserParamKey::REQUIRED . ' in ' . BrowserParamKey::PARAMS;
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
     * source row is read without one - and an empty list means no page rather than a page
     * declaring nothing.
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
     * Validates that every agent parking a throttled action can be told the verdict.
     *
     * A throttled action is parked in the worker of one agent - its own when the agent lists the
     * action in THROTTLED_ACTIONS, the page's SUBSCRIPTION_AGENT_TYPE agent when a page lists it -
     * and the verdict travels back by signal name, which names one agent type and cannot read the
     * addressee from the payload. A parking agent that does not declare the verdict is therefore
     * never told one: its parked actions wait out the deadline and then run unguarded, so the
     * guard reads as working while it guards nothing. That is silent, which is why it is refused
     * at startup (HIL-858).
     *
     * @param array $agents Agent registry
     * @param array $pages Page registry
     * @param array $agentSignalRoutes Computed agent signal route registry
     * @param array $agentSignalDtoRoutes Computed agent signal payload DTO registry
     * @param array $agentSignalIndexFields Computed index fields of indexed agent signal routes
     * @param list<string> $errors Validation error accumulator
     */
    private function validateThrottleVerdictRoutes(
        array $agents,
        array $pages,
        array $agentSignalRoutes,
        array $agentSignalDtoRoutes,
        array $agentSignalIndexFields,
        array &$errors,
    ): void {
        $agentClasses = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            $agentClasses[$agentType] = $agentClass;
        }

        // One entry per parking agent, sources accumulated into it: an agent that parks both for
        // itself and for three pages is one omission and reads better as one line.
        $parkingSources = [];
        foreach ($agentClasses as $agentType => $agentClass) {
            if ($agentClass::THROTTLED_ACTIONS !== []) {
                $parkingSources[$agentType][] = 'its own THROTTLED_ACTIONS';
            }
        }

        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            // A page naming an agent that is absent or malformed is already refused by
            // validatePageRoutes() and validateAgents(); saying it twice only lengthens the refusal.
            if ($pageClass::THROTTLED_ACTIONS === [] || !isset($agentClasses[$pageClass::SUBSCRIPTION_AGENT_TYPE])) {
                continue;
            }

            $parkingSources[$pageClass::SUBSCRIPTION_AGENT_TYPE][] = "PAGES[{$page}]";
        }

        $verdict = HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT;
        $verdictDto = ThrottleVerdictSignalData::class;
        $indexField = ThrottleVerdictSignalData::agentIndex;
        foreach ($parkingSources as $agentType => $sources) {
            $where = "AGENTS[{$agentType}] class {$agentClasses[$agentType]}";
            $parks = 'parks throttled actions (' . implode(', ', $sources) . ')';
            $holder = $agentSignalRoutes[$verdict] ?? null;
            if ($holder === null) {
                $errors[] = "{$where} {$parks} but no agent declares {$verdict} in AGENT_SIGNALS: its parked"
                    . " actions wait out the verdict deadline and then run unguarded. Declare it with {$verdictDto}.";
                continue;
            }

            if ($holder !== $agentType) {
                $errors[] = "{$where} {$parks} but {$verdict} is declared by AGENTS[{$holder}]: the verdict is"
                    . ' addressed to whoever declares it, so these parked actions wait out the verdict deadline and'
                    . ' then run unguarded. One agent holds throttled actions per application, because an agent'
                    . ' signal names one agent type and cannot read it from the payload.';
                continue;
            }

            if (($agentSignalDtoRoutes[$verdict] ?? null) !== $verdictDto) {
                $errors[] = "{$where} {$parks} and declares {$verdict} without {$verdictDto}: the verdict arrives"
                    . ' untyped and the parked actions run unguarded after the deadline.';
                continue;
            }

            if (
                AgentRegistry::requiresIndex($agents[$agentType])
                && ($agentSignalIndexFields[$verdict] ?? null) !== $indexField
            ) {
                $errors[] = "{$where} is indexed and {$parks}, but declares {$verdict} without an"
                    . " '" . AgentSignalConfigKey::INDEX_FIELD . "': the verdict names the parking instance in its"
                    . " {$indexField} payload field, and without the declaration it is delivered to the wrong"
                    . ' instance. Declare AgentSignalConfigKey::INDEX_FIELD => ' . "'{$indexField}'.";
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

                $unknownKeys = array_diff(array_keys($value), [
                    AgentSignalConfigKey::INDEX_FIELD,
                    AgentSignalConfigKey::NODE_FIELD,
                    AgentSignalConfigKey::DTO,
                ]);
                if ($unknownKeys !== []) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] contains"
                        . ' unknown config keys: ' . implode(', ', $unknownKeys);
                }

                // A config array is how a signal says WHICH replica it means, and there are two
                // ways to say it - an instance index, a node id, or both. Declaring neither leaves
                // an array that addresses nothing and reads as a route the author meant to finish.
                $indexField = $value[AgentSignalConfigKey::INDEX_FIELD] ?? null;
                $nodeField = $value[AgentSignalConfigKey::NODE_FIELD] ?? null;
                if ($indexField === null && $nodeField === null) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}] must declare"
                        . " a non-empty '" . AgentSignalConfigKey::INDEX_FIELD . "'"
                        . " or '" . AgentSignalConfigKey::NODE_FIELD . "'";
                }
                if ($indexField !== null && (!is_string($indexField) || $indexField === '')) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}]["
                        . AgentSignalConfigKey::INDEX_FIELD . '] must name a non-empty payload field';
                }
                if ($nodeField !== null && (!is_string($nodeField) || $nodeField === '')) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_SIGNALS[{$key}]["
                        . AgentSignalConfigKey::NODE_FIELD . '] must name a non-empty payload field';
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
                    continue;
                }

                // Map entry — command name key + config array value.
                if (!is_array($value)) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}] must be a"
                        . ' non-empty command name, a SignalDataInterface class, or a valid command config array';
                    continue;
                }

                $unknownKeys = array_diff(array_keys($value), [AgentCommandConfigKey::DTO]);
                if ($unknownKeys !== []) {
                    $errors[] = "AGENTS[{$agentType}] class {$agentClass} AGENT_COMMANDS[{$key}] contains"
                        . ' unknown config keys: ' . implode(', ', $unknownKeys);
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
            }
        }

        foreach ($declaredRoutes as $command => $agentType) {
            if (($commandAgentRoutes[$command] ?? null) !== $agentType) {
                $errors[] = "Agent command route {$command} is missing from computed command routes";
            }
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
     * Validates one stub registry entry: every copy field as non-empty text, and nothing else.
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
        $copyFields = [
            ProtectedModeStubConstants::TITLE,
            ProtectedModeStubConstants::MESSAGE,
            ProtectedModeStubConstants::BANNER_MESSAGE,
        ];
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
