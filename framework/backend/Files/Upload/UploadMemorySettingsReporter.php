<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\Logger;

/** Reports effective settings from a ready worker source without DB work on the master. */
final class UploadMemorySettingsReporter implements SourceChangeSubscriberInterface
{
    /** Registered master link; absent before registration. */
    private ?WorkerDaemonClient $client = null;

    /** Last value queued on this channel only; registration starts a fresh reporting span. */
    private ?int $lastReported = null;

    /** Last read failure, to avoid repeating the same log line. */
    private ?string $lastTrouble = null;

    /**
     * A new registration must receive its initial value even if the old channel saw the same one.
     *
     * @param WorkerDaemonClient $client Newly registered worker link
     */
    public function onRegistered(WorkerDaemonClient $client): void
    {
        $this->client = $client;
        $this->lastReported = null;
        $this->report();
    }

    /** Queues only changed values, deferring until the settings source and accessor are ready. */
    public function report(): void
    {
        if ($this->client === null || Hilos::$setting === null
            || !SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::settings)) {
            return;
        }

        try {
            $value = Hilos::$setting[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->int();
            $frame = new WorkerUploadSettingsDTO($value);
        } catch (DatabaseException|SettingException|InvalidFormatException $exception) {
            $trouble = $exception->getMessage();
            if ($trouble !== $this->lastTrouble) {
                Logger::error('Upload memory settings could not be read: ' . $trouble);
            }
            $this->lastTrouble = $trouble;

            return;
        }

        $this->lastTrouble = null;
        if ($value === $this->lastReported) {
            return;
        }

        $this->client->send($frame);
        $this->lastReported = $value;
    }

    /**
     * Updates carry no key, so re-read; creates and deletes of other keys need no report.
     *
     * @param SourceChange $change Source mutation
     * @param SourceChangeProvenance $provenance Both local and arriving changes affect the effective value
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt() || $change->sourceKey !== HilosDbContext::settings) {
            return;
        }

        $key = $change->row[Setting::key] ?? null;
        if ($key !== null && $key !== UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY) {
            return;
        }

        $this->report();
    }
}
