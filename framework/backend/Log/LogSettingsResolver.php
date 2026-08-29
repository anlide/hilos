<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Database\Settings\Validation\CronExpressionRule;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Throwable;

/**
 * Builds the rotation and retention policies from the settings, with the environment beneath them (HIL-760).
 *
 * The reader behind {@see LogRotationAgent}: it is asked for a policy on every throttled check, so
 * an administrator's edit takes effect within seconds instead of at the next restart of the node.
 *
 * Two ways down to the environment, and they are not the same thing. A project that never folded
 * {@see LogSettingsCatalog} into its catalog has no such keys, so the settings are not consulted at
 * all and nothing is wrong — that is the plain env installation. Trouble is the other way: the
 * settings layer is not initialized, the read throws, or the stored value does not pass the rule
 * its key declares. Then the environment is used as well, but a line is owed to the journal —
 * rotation is not something to stop over.
 *
 * The complaint is raised when the outcome CHANGES, not on every check: the same failing value
 * asked about every five seconds would flood the very journal this class configures. A recovery
 * clears the memory silently, so a fault that comes back is reported again.
 */
final class LogSettingsResolver
{
    /** Scope name under which the rotation outcome is remembered. */
    private const string SCOPE_ROTATION = 'rotation';

    /** Scope name under which the retention outcome is remembered. */
    private const string SCOPE_RETENTION = 'retention';

    /** @var array<string, string> Last trouble text per scope, so an unchanged fault stays silent */
    private array $lastTrouble = [];

    /** @var list<string> What went wrong during the policy build in progress */
    private array $pending = [];

    /** @var list<string> Complaints raised and not yet taken by the caller */
    private array $complaints = [];

    /**
     * Builds the rotation trigger policy, falling back to the environment on trouble.
     *
     * @return LogRotationTriggerPolicy Policy carrying the age, size, and schedule axes
     */
    public function rotationPolicy(): LogRotationTriggerPolicy
    {
        $maxAge = $this->integerValue(LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS);
        $maxSize = $this->integerValue(LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES);
        $cron = $this->scheduleValue(LogSettingsCatalog::ROTATION_CRON);

        $policy = $maxAge === null || $maxSize === null || $cron === null
            ? $this->rotationFromEnv()
            : new LogRotationTriggerPolicy($maxAge, $maxSize, $cron);

        $this->conclude(self::SCOPE_ROTATION);

        return $policy;
    }

    /**
     * Builds the archive retention policy, falling back to the environment on trouble.
     *
     * @return LogArchiveRetentionPolicy Policy carrying the keep-count and max-age criteria
     */
    public function retentionPolicy(): LogArchiveRetentionPolicy
    {
        $keepBatches = $this->integerValue(LogSettingsCatalog::ARCHIVE_RETENTION_KEEP_BATCHES);
        $maxAge = $this->integerValue(LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS);

        $policy = $keepBatches === null || $maxAge === null
            ? $this->retentionFromEnv()
            : new LogArchiveRetentionPolicy($keepBatches, $maxAge);

        $this->conclude(self::SCOPE_RETENTION);

        return $policy;
    }

    /**
     * Hands over one complaint raised since the last call, oldest first.
     *
     * @return ?string Line for the journal, or null when there is nothing new to say
     */
    public function takeComplaint(): ?string
    {
        return array_shift($this->complaints);
    }

    /**
     * Reads one numeric setting, or reports why it cannot be used.
     *
     * @param string $key Setting key
     * @return ?int Value to use, or null when the environment should answer instead
     */
    private function integerValue(string $key): ?int
    {
        $value = $this->rawValue($key);
        if ($value === null) {
            return null;
        }

        $refusal = NonNegativeIntegerRule::validate($value);
        if ($refusal !== null) {
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal})");

            return null;
        }

        return (int)$value;
    }

    /**
     * Reads the schedule setting, or reports why it cannot be used.
     *
     * @param string $key Setting key
     * @return ?string Expression to use, or null when the environment should answer instead
     */
    private function scheduleValue(string $key): ?string
    {
        $value = $this->rawValue($key);
        if ($value === null) {
            return null;
        }

        $refusal = CronExpressionRule::validate($value);
        if ($refusal !== null) {
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal})");

            return null;
        }

        return (string)$value;
    }

    /**
     * Reads the effective value of a key, as stored or as the catalog default.
     *
     * @param string $key Setting key
     * @return mixed Raw effective value, or null when the environment should answer instead
     */
    private function rawValue(string $key): mixed
    {
        $settings = Hilos::$setting;
        if ($settings === null) {
            $this->trouble('settings are not initialized in this process');

            return null;
        }

        // A project that never folded the log fragment into its catalog is not in trouble:
        // it is the installation configured by environment alone, and it stays that way.
        if (!isset($settings[$key])) {
            return null;
        }

        try {
            return $settings->effectiveValueFor($key);
        } catch (Throwable $exception) {
            $this->trouble("setting '{$key}' could not be read: " . $exception->getMessage());

            return null;
        }
    }

    /**
     * Builds the rotation policy from the environment, inert when even that cannot be read.
     *
     * @return LogRotationTriggerPolicy Policy from the environment, or one with every axis off
     */
    private function rotationFromEnv(): LogRotationTriggerPolicy
    {
        try {
            return LogRotationTriggerPolicy::fromEnv();
        } catch (EnvException $exception) {
            $this->trouble('log rotation environment is unreadable: ' . $exception->getMessage());

            return new LogRotationTriggerPolicy(0, 0);
        }
    }

    /**
     * Builds the retention policy from the environment, inert when even that cannot be read.
     *
     * @return LogArchiveRetentionPolicy Policy from the environment, or one that keeps everything
     */
    private function retentionFromEnv(): LogArchiveRetentionPolicy
    {
        try {
            return LogArchiveRetentionPolicy::fromEnv();
        } catch (EnvException $exception) {
            $this->trouble('log retention environment is unreadable: ' . $exception->getMessage());

            return new LogArchiveRetentionPolicy(0, 0);
        }
    }

    /**
     * Notes what went wrong during the policy build in progress.
     *
     * @param string $text What is wrong, in one line
     */
    private function trouble(string $text): void
    {
        if (!in_array($text, $this->pending, true)) {
            $this->pending[] = $text;
        }
    }

    /**
     * Closes a policy build: one complaint when its outcome differs from the previous one.
     *
     * A build that went cleanly forgets the scope, so a fault that returns later is reported
     * again rather than swallowed as "already said".
     *
     * @param string $scope Scope the outcome is remembered under
     */
    private function conclude(string $scope): void
    {
        $trouble = $this->pending === [] ? null : implode('; ', $this->pending);
        $this->pending = [];

        if ($trouble === null) {
            unset($this->lastTrouble[$scope]);

            return;
        }

        if (($this->lastTrouble[$scope] ?? null) !== $trouble) {
            $this->complaints[] = 'Log settings: ' . $trouble . '; using the environment instead';
        }

        $this->lastTrouble[$scope] = $trouble;
    }
}
