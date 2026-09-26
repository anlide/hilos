<?php

declare(strict_types=1);

namespace Hilos\Files\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\HilosFiles;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * The files library: the one owner of the files registry (HIL-336).
 *
 * An entity library in the sense of docs/agents/architecture/entity-libraries.md. What it owns is
 * the hilos_file table: every row is written here - created, marked bound, removed - and nowhere
 * else. A project reaches it through {@see HilosFiles::markBound()}, which sends
 * {@see HilosSignalConstants::HILOS_FILE_BIND} here.
 *
 * It also keeps the registry clean. A row is born unbound, and a row nobody bound within the
 * `files.unbound_ttl_hours` setting is taken by the janitor: the row first, then the file of the
 * same name in the files directory ({@see FsContext::FILES}). Row first, because a file left
 * behind costs disk space while a row left behind points at nothing. A row whose removal the
 * database refuses with a foreign key is one the project linked without saying so - its bind frame
 * was lost on the way - so the janitor marks it bound instead and keeps the file.
 *
 * The janitor never walks the directory: files without a row are not its own, and in the chat
 * demo that directory also holds the attachments published before the registry existed.
 *
 * Abstract by convention, as the notifications library is: the files registry has no project
 * half, and a project subclass adds nothing but its name.
 */
abstract class AbstractFilesLibraryAgent extends AbstractAgent
{
    /**
     * The files registry, claimed outright: no other agent writes it.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::files => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_FILES_LIBRARY;

    /**
     * The one frame the library is addressed by: the project linked these files.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_FILE_BIND => FileBindSignalData::class,
    ];

    /** Name of the cron rule that removes files nobody linked. */
    public const string FILES_SWEEP_RULE = 'hilos_files_sweep_unbound';

    /** Every 15 minutes, as the session sweep. */
    public const string FILES_SWEEP_CRON = '*/15 * * * *';

    /** Maximum unbound rows considered by one tick. */
    public const int FILES_SWEEP_BATCH = 100;

    /** @var ?CronRule Schedule of the unbound-file sweep */
    private ?CronRule $filesSweepRule = null;

    /** Whether a full sweep batch that did something asks the next tick to continue immediately */
    private bool $filesSweepBacklog = false;

    /**
     * Arms the unbound-file sweep.
     */
    public function onStart(): void
    {
        $this->filesSweepRule = new CronRule(self::FILES_SWEEP_RULE, self::FILES_SWEEP_CRON);
    }

    /**
     * Runs the sweep when it is due, or at once while a full batch left more behind.
     *
     * @throws HilosException On database or settings failure
     */
    public function onTick(): void
    {
        if (!$this->filesSweepBacklog && $this->filesSweepRule?->shouldRun() !== true) {
            return;
        }

        $this->sweepUnboundFiles();
    }

    /**
     * The library holds nothing across a stop: its state is the registry table, which outlives
     * the process that owns it.
     */
    public function onStop(): void
    {
    }

    /**
     * Marks bound the files the project says it linked.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this library declares
     * @throws InvalidAgentSignalPayloadException When the payload is not the one its name promises
     * @throws HilosException When a row cannot be read or written
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_FILE_BIND:
                if (!$data->data instanceof FileBindSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, FileBindSignalData::class, $data->data);
                }
                $this->markBound($data->data->fileIds);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Marks each named row bound; a row the registry does not hold is reported and skipped.
     *
     * @param list<int> $fileIds Ids of the registry rows the project linked
     * @throws HilosException When a row cannot be read or written
     */
    private function markBound(array $fileIds): void
    {
        foreach ($fileIds as $fileId) {
            $file = Hilos::$db->files[$fileId];
            if ($file === null) {
                $this->logAgentWarning("File {$fileId} is not in the registry");
                continue;
            }

            $file->actions->markBound();
        }
    }

    /**
     * Removes one bounded batch of files nobody linked within the configured lifetime.
     *
     * Two refusals are expected and caught, each narrowly: a foreign key on the row, which is the
     * database saying the project did link the file, and a file that stays on disk, which is
     * reported and not undone. Anything else - the database, the setting - leaves the tick, as the
     * session sweep's failures do (docs/agents/code-style/wiring-refusals.md).
     *
     * @throws HilosException On database or settings failure
     */
    private function sweepUnboundFiles(): void
    {
        $this->filesSweepBacklog = false;

        $ttlHours = Hilos::$setting[FilesSettingsCatalog::UNBOUND_TTL_HOURS_KEY]->int();
        if ($ttlHours <= 0) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', time() - $ttlHours * TimeConstants::SECONDS_PER_HOUR);
        $files = Hilos::$db->files->findUnboundBefore($cutoff, self::FILES_SWEEP_BATCH);
        $removed = 0;
        $marked = 0;

        foreach ($files as $file) {
            $fileId = $file->id;
            $storedName = $file->storedName;

            try {
                $file->actions->delete();
            } catch (ForeignKeyConstraintException) {
                $file->actions->markBound();
                $this->logAgentWarning("File {$fileId} is referenced by a project row; marked bound");
                $marked++;
                continue;
            }
            $removed++;

            try {
                Hilos::$fs->files[$storedName]->unlink();
            } catch (FileDeleteException $e) {
                $this->logAgentError("Orphan file {$storedName} left on disk: " . $e->getMessage());
            }
        }

        $done = $removed + $marked;
        $this->filesSweepBacklog = count($files) === self::FILES_SWEEP_BATCH && $done > 0;

        if ($done > 0) {
            $this->logAgentInfo("Files sweep: removed {$removed} unbound, marked {$marked} referenced");
        }
    }
}
