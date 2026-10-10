<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\Logger;

/**
 * Reports the worker's effective restart policy to the master on registration and settings edits.
 *
 * The master cannot read the database. Updates of a setting's value carry no key, so an update
 * to any settings row triggers a re-read, but only a changed value produces a worker frame.
 * Registration confirms the worker link before settings readiness is delivered. A cataloged key
 * therefore waits for that readiness instead of making a read the DB guard must refuse.
 */
final class ProtectedModeSettingsReporter implements SourceChangeSubscriberInterface
{
    /** @var ?bool Last value sent; null means this worker has never sent one */
    private ?bool $lastReported = null;

    /** @var ?string Last read failure, kept to avoid repeating the same log line */
    private ?string $lastTrouble = null;

    /**
     * @param WorkerDaemonClient $client Registered link to this worker's master
     */
    public function __construct(private readonly WorkerDaemonClient $client)
    {
    }

    /** Reads when ready and sends only a value different from this worker's last report. */
    public function report(): void
    {
        try {
            $setting = Hilos::$setting;
            if ($setting === null || !isset($setting[ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL])) {
                $value = ProtectedModeSettingsCatalog::DEFAULT_MANUAL_RESTART_IS_NORMAL;
            } else {
                // Registration precedes the master's settings-readiness frame. A read before that
                // frame is refused and would abort registration before source interest is sent.
                if (!SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::settings)) {
                    return;
                }
                $value = $setting[ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL]->bool();
            }
        } catch (DatabaseException|SettingException $exception) {
            $trouble = $exception->getMessage();
            if ($trouble !== $this->lastTrouble) {
                Logger::error('Protected-mode settings could not be read: ' . $trouble);
            }
            $this->lastTrouble = $trouble;

            return;
        }

        $this->lastTrouble = null;
        if ($value === $this->lastReported) {
            return;
        }

        $this->client->send(new WorkerProtectedModeSettingsDTO($value));
        $this->lastReported = $value;
    }

    /**
     * An update carries no key, so re-read it; a create or delete for another key can be ignored.
     *
     * @param SourceChange $change Fact describing what changed
     * @param SourceChangeProvenance $provenance Origin is immaterial to the effective setting
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->isRt() || $change->sourceKey !== HilosDbContext::settings) {
            return;
        }

        $key = $change->row[Setting::key] ?? null;
        if ($key !== null && $key !== ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL) {
            return;
        }

        $this->report();
    }
}
