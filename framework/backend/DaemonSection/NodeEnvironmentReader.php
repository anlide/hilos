<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;

/** Builds one node-local reading from the catalog, process cache and fresh files. */
final class NodeEnvironmentReader
{
    /**
     * @param EnvAccessor $env Environment accessor of this node's process
     * @param string $nodeId Node doing the read
     * @param int $readAt Reading timestamp
     * @return NodeEnvironmentReading Values retained only on this node
     * @throws EnvException When the catalog cannot be read
     */
    public static function read(EnvAccessor $env, string $nodeId, int $readAt): NodeEnvironmentReading
    {
        $files = $env->readFilesFromDisk();
        $catalogKeys = $env->catalogKeys();
        $keys = [];
        foreach ($catalogKeys as $key) {
            $process = $env->resolutionFor($key);
            $onDisk = $env->resolutionOnDisk($key, $files);
            $disk = $process->source === $onDisk->source && $process->value === $onDisk->value
                ? null
                : $onDisk;
            $keys[] = new NodeEnvironmentKey(
                $key,
                $env->typeFor($key),
                $env->requiredFor($key),
                $env->declaredByFramework($key),
                $env->sensitiveFor($key),
                $env->perNodeFor($key),
                $env->visibleInAdminViewMode($key),
                $process,
                $disk,
            );
        }

        $declared = array_fill_keys($catalogKeys, true);
        $orphans = [];
        foreach ($files->env as $key => $value) {
            if (!isset($declared[$key])) {
                $orphans[] = new NodeEnvironmentOrphan($key, $value);
            }
        }

        return new NodeEnvironmentReading($nodeId, $readAt, $keys, $orphans);
    }
}
