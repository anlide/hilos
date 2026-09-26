<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\File;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos;

/**
 * Registry of published files: the hilos_file table, the library that owns it, and the janitor
 * of files nobody linked (HIL-336).
 *
 * The library is required because it is the only writer: a project that declared the feature
 * and registered no {@see AbstractFilesLibraryAgent} would send every bind frame nowhere. The
 * settings library is required because the janitor reads its lifetime from a setting, and the
 * catalog fragment because that setting must exist on the project's settings screen.
 *
 * The files directory is not a requirement here: whether the FS context names one is known only
 * once the context is configured, so {@see Hilos::init()} refuses the start after that step
 * instead ({@see FsContext::FILES}).
 */
final class FilesFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Files feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::FILES;
    }

    /**
     * @return FeatureRequirements The files library, the settings library, the lifetime catalog fragment and the file table
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredAgents: [HilosAgentType::HILOS_FILES_LIBRARY],
            requiredSharedAgents: [HilosAgentType::HILOS_SETTINGS_LIBRARY],
            requiredCatalogFragments: [FilesSettingsCatalog::class],
            requiredDbTables: [File::_table],
        );
    }
}
