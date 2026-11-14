<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\MomentStorage;

use Composer\Autoload\ClassLoader;
use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\ScannedRoots;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use RecursiveDirectoryIterator;
use RuntimeException;
use UnexpectedValueException;

/** Production semantic inputs only: no application bootstrap, facade initialization or database access. */
final class MomentStorageSources
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const array EXCLUDED_PATHS = [
        'Database/Migration/Schema', 'Database/Migration/Change', 'tests', 'fixtures', 'generated',
    ];

    /**
     * Only declaration metadata is loaded through a temporary production PSR-4 loader.
     * The source index reads the application's BACKUP_CATALOG registration without loading its facade.
     * Reports and metadata maps belong to this one pass; no runtime collection or cache is involved.
     *
     * @param string $repositoryRoot Repository containing the framework and optionally demos
     * @return array{roots: list<string>, excludedPaths: array<string, list<string>>, catalog: list<array{
     *     entity: ?string, declaration: string, table: string, column: string,
     *     mapped: bool, frameworkOwned: bool, projectStorage: ?string}>} Complete production inputs
     */
    public static function forRepository(string $repositoryRoot): array
    {
        self::requireDirectory($repositoryRoot . '/framework/backend');
        $demoDirectory = $repositoryRoot . '/demo';
        if (file_exists($demoDirectory) || is_link($demoDirectory)) {
            self::requireDirectory($demoDirectory);
        }
        $roots = [];
        $excludedPaths = [];
        $projects = [];
        $loader = new ClassLoader();
        foreach (ScannedRoots::all($repositoryRoot) as $root => $kind) {
            if ($kind !== RootKind::Production) {
                continue;
            }
            if ($root !== 'framework/backend') {
                // Prove the parent is accessible before interpreting a missing backend as optional absence.
                self::requireDirectory($repositoryRoot . '/' . dirname($root));
                $absolute = $repositoryRoot . '/' . $root;
                if (!file_exists($absolute) && !is_link($absolute)) {
                    continue;
                }
                self::requireDirectory($absolute);
            }
            $roots[] = $root;
            $excludedPaths[$root] = self::EXCLUDED_PATHS;
            if ($root === 'framework/backend') {
                continue;
            }
            $composer = json_decode(self::read($repositoryRoot . '/' . dirname($root) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $mappings = $composer['autoload']['psr-4'] ?? null;
            if (!is_array($mappings) || $mappings === []) {
                throw new RuntimeException('Missing production PSR-4 mappings for ' . $root);
            }
            foreach ($mappings as $namespace => $directories) {
                if (!is_string($namespace) || $namespace === '' || !str_ends_with($namespace, '\\')) {
                    throw new RuntimeException('Invalid production PSR-4 namespace for ' . $root);
                }
                foreach (is_array($directories) ? $directories : [$directories] as $directory) {
                    if (!is_string($directory) || $directory === '') {
                        throw new RuntimeException('Invalid production PSR-4 directory for ' . $root);
                    }
                    $absolute = $repositoryRoot . '/' . dirname($root) . '/' . rtrim($directory, '/');
                    if (!is_dir($absolute) || !is_readable($absolute)) {
                        throw new RuntimeException('Unreadable production PSR-4 directory: ' . $absolute);
                    }
                    $loader->addPsr4($namespace, $absolute);
                    $projects[] = ['namespace' => $namespace, 'directory' => $absolute];
                }
            }
        }

        $index = SourceIndex::forRoots($repositoryRoot, $roots, $excludedPaths);
        $loader->register(prepend: true);
        try {
            $entities = EntitySchemaAudit::frameworkEntities();
            $providers = [FrameworkTablesWithoutEntity::class];
            $entityNamespace = substr(Entity::class, strlen('Hilos\\'), -strlen('Entity'));
            foreach ($projects as $project) {
                $directory = $project['directory'] . '/' . str_replace('\\', '/', rtrim($entityNamespace, '\\'));
                if (is_dir($directory)) {
                    array_push($entities, ...EntitySchemaAudit::discoverEntities($directory, $project['namespace'] . $entityNamespace));
                }
                $application = $project['namespace'] . 'Hilos';
                $registration = $index->resolveConstant($application, 'BACKUP_CATALOG');
                if ($registration === null || strcasecmp(trim($registration), 'null') === 0) {
                    continue;
                }
                $catalogClass = $index->resolveConstantClass($application, 'BACKUP_CATALOG');
                if ($catalogClass === null || !is_a($catalogClass, CatalogProviderInterface::class, true)) {
                    throw new RuntimeException('Invalid BACKUP_CATALOG metadata for ' . $application);
                }
                $declared = $catalogClass::getCatalog()[BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY] ?? [];
                if (!is_array($declared)) {
                    throw new RuntimeException('Invalid tables-without-Entity registration for ' . $application);
                }
                foreach ($declared as $connection => $provider) {
                    if (!is_int($connection) || !is_string($provider) || !is_a($provider, TablesWithoutEntityProvider::class, true)) {
                        throw new RuntimeException('Invalid moment provider registration for ' . $application);
                    }
                    $providers[] = $provider;
                }
            }

            $catalog = [];
            foreach (array_unique($entities) as $entity) {
                foreach (MomentColumns::forEntity($entity) as $moment) {
                    $declaration = $entity;
                    while (($parent = get_parent_class($declaration)) !== false && is_a($parent, Entity::class, true)
                        && in_array($moment->column, $parent::_moments, true)) {
                        $declaration = $parent;
                    }
                    $catalog[] = [
                        'entity' => $entity, 'declaration' => $declaration, 'table' => $entity::_table,
                        'column' => $moment->column, 'mapped' => $moment->mapped,
                        'frameworkOwned' => $moment->frameworkOwned, 'projectStorage' => $moment->projectStorage,
                    ];
                }
            }
            foreach (array_unique($providers) as $provider) {
                foreach (MomentColumns::forProvider($provider) as $table => $moments) {
                    foreach ($moments as $moment) {
                        $declaration = $moment->frameworkOwned ? FrameworkTablesWithoutEntity::class : $provider;
                        while (($parent = get_parent_class($declaration)) !== false && is_a($parent, TablesWithoutEntityProvider::class, true)
                            && in_array($moment->column, $parent::moments()[$table] ?? [], true)) {
                            $declaration = $parent;
                        }
                        $catalog[] = [
                            'entity' => null, 'declaration' => $declaration, 'table' => (string)$table,
                            'column' => $moment->column, 'mapped' => $moment->mapped,
                            'frameworkOwned' => $moment->frameworkOwned, 'projectStorage' => $moment->projectStorage,
                        ];
                    }
                }
            }
            $unique = [];
            foreach ($catalog as $column) {
                $unique[json_encode($column, self::JSON_FLAGS)] = $column;
            }
            ksort($unique, SORT_STRING);

            return ['roots' => $roots, 'excludedPaths' => $excludedPaths, 'catalog' => array_values($unique)];
        } finally {
            $loader->unregister();
        }
    }

    /**
     * Opening path/. checks both reading and traversal with the effective filesystem credentials.
     * The scanner's iterator validates access only; ScannedRoots still owns root enumeration.
     *
     * @param string $path Existing production or discovery directory that must be inspectable
     */
    private static function requireDirectory(string $path): void
    {
        try {
            new RecursiveDirectoryIterator($path . '/.', RecursiveDirectoryIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $failure) {
            throw new RuntimeException('Cannot inspect moment source directory: ' . $path, previous: $failure);
        }
    }

    /**
     * @param string $path Required metadata file
     * @return string Complete contents; a failed read is never an empty catalog
     */
    private static function read(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Unreadable moment metadata: ' . $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read moment metadata: ' . $path);
        }

        return $contents;
    }
}
