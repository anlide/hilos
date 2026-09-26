<?php

declare(strict_types=1);

namespace Hilos\Files;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Hilos;

/**
 * HilosFiles - the project's door into the files registry (HIL-336).
 *
 * The facade global {@see Hilos::$files}. The registry is written in one process only, the one
 * of {@see AbstractFilesLibraryAgent}, so the door writes nothing: it sends a frame there.
 */
class HilosFiles
{
    /**
     * Tells the files library that the project has linked these files to its own records.
     *
     * Call it after the project's own link is written. Fire-and-forget: nothing answers, and
     * with no signal router in the process (a CLI context) the frame reaches nobody. A frame
     * lost on the way costs no file - the janitor reads a foreign-key refusal on a project row
     * as the same fact - but a project without such a key has only this call to keep its files.
     *
     * @param list<int> $fileIds Ids of the registry rows the project linked
     * @throws FeatureNotDeclaredException When the project did not declare HilosFeature::FILES
     * @throws InvalidArgumentException When the bind signal cannot be named or queued
     */
    public function markBound(array $fileIds): void
    {
        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            throw FeatureNotDeclaredException::forFeature(HilosFeature::FILES);
        }
        if ($fileIds === []) {
            return;
        }

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_FILE_BIND),
            signalData: new AgentSignalData(data: new FileBindSignalData($fileIds)),
        );
    }
}
