<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Composer\Autoload\ClassLoader;
use Hilos\Database\DatabaseException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use JsonException;
use stdClass;

/** Reads source metadata and registers project PSR-4 paths without booting any project. */
final class FrameworkMigrationProjectRegistry
{
    private const string TRACK = '/backend/Database/Migration/Schema';

    /**
     * The loader and parsed records belong to this CLI operation; no shared DB or RT state is involved.
     * Every project's paths are registered before resolving facades that may reference another project.
     *
     * @param string $path Explicit JSON registry, with project roots relative to that file
     * @return list<FrameworkMigrationProject> Typed source targets in registry order
     * @throws DatabaseException When JSON, Composer PSR-4 metadata or a facade is invalid
     * @throws FsException When the registry or project Composer file cannot be read
     */
    public static function load(string $path): array
    {
        $registry = self::readJson($path);
        if (!is_array($registry->projects ?? null) || $registry->projects === []) {
            throw new DatabaseException("Framework project registry {$path} needs a nonempty projects list");
        }

        $loader = new ClassLoader();
        $roots = [];
        foreach ($registry->projects as $entry) {
            if (
                !$entry instanceof stdClass || !is_string($entry->root ?? null) || trim($entry->root) === ''
                || !is_string($entry->facade ?? null) || trim($entry->facade) === ''
            ) {
                throw new DatabaseException("Framework project registry {$path} needs root and facade strings for every project");
            }
            $root = self::resolvePath(dirname($path), $entry->root);
            $composer = self::readJson($root . '/composer.json');
            $psr4 = $composer->autoload->{'psr-4'} ?? null;
            if (!$psr4 instanceof stdClass) {
                throw new DatabaseException("Project {$root}/composer.json declares no PSR-4 autoload map");
            }
            foreach (get_object_vars($psr4) as $prefix => $paths) {
                $paths = is_string($paths) ? [$paths] : $paths;
                if (!is_array($paths) || !array_is_list($paths) || $paths === [] || ($prefix !== '' && !str_ends_with($prefix, '\\'))) {
                    throw new DatabaseException("Project {$root}/composer.json has invalid PSR-4 paths for {$prefix}");
                }
                foreach ($paths as $directory) {
                    if (!is_string($directory) || $directory === '') {
                        throw new DatabaseException("Project {$root}/composer.json has an invalid PSR-4 directory");
                    }
                    $loader->addPsr4($prefix, self::resolvePath($root, $directory));
                }
            }
            $roots[] = $root;
        }

        $loader->register();
        $projects = [];
        foreach ($registry->projects as $index => $entry) {
            try {
                $projects[] = new FrameworkMigrationProject($roots[$index] . self::TRACK, $entry->facade);
            } catch (DatabaseException $exception) {
                $loader->unregister();
                throw new DatabaseException(
                    "Project {$roots[$index]}: cannot load Hilos facade {$entry->facade}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }

        return $projects;
    }

    /**
     * @param string $path JSON metadata file
     * @return stdClass Parsed object at the file boundary
     * @throws DatabaseException When the file is malformed or has no object root
     * @throws FsException When the file cannot be read
     */
    private static function readJson(string $path): stdClass
    {
        try {
            $metadata = json_decode(FsPath::read($path), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DatabaseException("Invalid JSON in {$path}: {$exception->getMessage()}", previous: $exception);
        }
        if (!$metadata instanceof stdClass) {
            throw new DatabaseException("Expected a JSON object in {$path}");
        }

        return $metadata;
    }

    /**
     * @param string $base Directory owning the relative path
     * @param string $path Absolute or relative metadata path
     * @return string Resolved source path, without reading environment files
     */
    private static function resolvePath(string $base, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $base . '/' . $path;
    }
}
