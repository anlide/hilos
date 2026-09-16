<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Settings\Validation\CronExpressionRule;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Throwable;

/**
 * Builds the log policies and intervals from the settings, with the environment beneath them (HIL-760).
 *
 * The reader behind {@see LogStoreAgent}: it is asked for a policy on every throttled check, so an
 * administrator's edit takes effect within seconds instead of at the next restart of the node.
 * {@see LogStoreAgent} asks it the same way for the index-push interval (HIL-942).
 *
 * Two ways down to the environment, and they are not the same thing. A project that never folded
 * {@see LogSettingsCatalog} into its catalog has no such keys, so the settings are not consulted at
 * all and nothing is wrong — that is the plain env installation. Trouble is the other way: the
 * settings layer is not initialized, the read throws, or the stored value does not pass the rule
 * its key declares. Then the environment is used as well, but a line is owed to the journal —
 * rotation is not something to stop over. The environment itself can be the thing that cannot
 * answer, and the policies contain that on their own (HIL-682); what is owed to the journal then
 * is the consequence, which only this class knows — an axis that is off, or a retention that will
 * evict nothing.
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

    /** Scope name under which the index-push outcome is remembered. */
    private const string SCOPE_INDEX_PUSH = 'index push';

    /** Scope name under which the takeout-undo window outcome is remembered. */
    private const string SCOPE_TAKEOUT_UNDO = 'takeout undo window';

    /** Scope name under which the free-space threshold outcome is remembered. */
    private const string SCOPE_FREE_SPACE = 'free space threshold';

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
     * Reads how often a node may send its log index to the cluster aggregator.
     *
     * A written row speaks for every node of the cluster, because the database is shared; with no
     * row, each node answers from its own environment beneath the setting, the price the log design
     * names under "Settings Above The Environment".
     *
     * Asked for at the moment the interval is needed, like the policies above, so an edit is obeyed
     * without restarting anything. The node's log store agent is the one that asks (HIL-942).
     *
     * @return int Milliseconds between two frames, never below the floor its rule declares
     */
    public function pushIntervalMs(): int
    {
        $setting = $this->pushIntervalValue(LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS);
        $interval = $setting ?? $this->pushIntervalFromEnv();

        $this->conclude(self::SCOPE_INDEX_PUSH);

        return $interval;
    }

    /**
     * Reads how long a confirmed batch stays protected from the pruner (HIL-759).
     *
     * The ladder every other key here stands on, and the reason the NODE reports the answer
     * rather than the screen working it out: with no row written the value bottoms out in the
     * environment, which is per node, so three nodes of one cluster can honestly promise three
     * different windows. A written row overrides all of them at once, and then the number is the
     * same everywhere because the database is.
     *
     * One number and not two, and both sides of the promise are obliged to read THIS one: whoever
     * deletes a confirmed batch and whoever tells an operator when that may happen. A second
     * reading of the same promise could drift from the first and leave a screen offering to
     * withdraw a batch that is already gone.
     *
     * @return int Seconds a confirmed batch is protected for, 0 when the pruner is not to wait
     */
    public function takeoutUndoWindowSeconds(): int
    {
        $written = $this->integerValue(LogSettingsCatalog::TAKEOUT_UNDO_WINDOW_SECONDS);
        $window = $written ?? $this->takeoutUndoWindowFromEnv();

        $this->conclude(self::SCOPE_TAKEOUT_UNDO);

        return $window;
    }

    /**
     * Reads the share of the log volume the installation keeps free, in percent (HIL-869).
     *
     * Asked on every publication of the node index, the way the undo window is, so an
     * administrator's edit reaches the screen with the next frame rather than at the next restart.
     * The NODE answers rather than the page working it out, and for the same reason: with no row
     * written the value bottoms out in the environment, which is per node, so a page reading its
     * own environment on behalf of another node would report a threshold that node does not hold.
     *
     * Zero is an answer and not an absence — the days are then counted to a full disk.
     *
     * @return int Percentage of the volume kept free, 0 when the installation keeps none
     */
    public function freeSpaceThresholdPercent(): int
    {
        $written = $this->freeSpaceThresholdValue(LogSettingsCatalog::FREE_SPACE_THRESHOLD_PERCENT);
        $threshold = $written ?? $this->freeSpaceThresholdFromEnv();

        $this->conclude(self::SCOPE_FREE_SPACE);

        return $threshold;
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
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal}), using the environment instead");

            return null;
        }

        return (int)$value;
    }

    /**
     * Reads the index-push setting, or reports why it cannot be used.
     *
     * @param string $key Setting key
     * @return ?int Interval to use, or null when the environment should answer instead
     */
    private function pushIntervalValue(string $key): ?int
    {
        $value = $this->rawValue($key);
        if ($value === null) {
            return null;
        }

        $refusal = LogIndexPushIntervalRule::validate($value);
        if ($refusal !== null) {
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal}), using the environment instead");

            return null;
        }

        return (int)$value;
    }

    /**
     * Reads the free-space threshold setting, or reports why it cannot be used.
     *
     * @param string $key Setting key
     * @return ?int Threshold to use, or null when the environment should answer instead
     */
    private function freeSpaceThresholdValue(string $key): ?int
    {
        $value = $this->rawValue($key);
        if ($value === null) {
            return null;
        }

        $refusal = LogFreeSpaceThresholdRule::validate($value);
        if ($refusal !== null) {
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal}), using the environment instead");

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
            $this->trouble("setting '{$key}' is refused by its own rule ({$refusal}), using the environment instead");

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
            $this->trouble('settings are not initialized in this process, using the environment instead');

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
            $this->trouble("setting '{$key}' could not be read: " . $exception->getMessage() . ', using the environment instead');

            return null;
        }
    }

    /**
     * Builds the rotation policy from the environment, reporting the axes it could not read.
     *
     * @return LogRotationTriggerPolicy Policy from the environment, with an unreadable axis off
     */
    private function rotationFromEnv(): LogRotationTriggerPolicy
    {
        $policy = LogRotationTriggerPolicy::fromEnv();
        foreach ($policy->unreadable as $key => $reason) {
            $this->trouble("{$key} is unreadable, that axis is off ({$reason})");
        }

        return $policy;
    }

    /**
     * Reads the push interval from the environment, falling back to the literal below the floor.
     *
     * An environment value under the minimum is refused rather than clamped up to it, for the
     * reason the rule gives: there is no "off" here, so a too-small number is a mistake and not an
     * instruction, and obeying a corrected version of it would hide the mistake.
     *
     * @return int Interval from the environment, or the fallback when it cannot answer usably
     */
    private function pushIntervalFromEnv(): int
    {
        $fallback = LogSettingsCatalog::INDEX_PUSH_INTERVAL_FALLBACK_MS;
        $env = Hilos::$env;
        if ($env === null) {
            $this->trouble(
                'log index push environment is unreadable: no environment in this process, using the fallback of '
                . LogSettingsCatalog::INDEX_PUSH_INTERVAL_FALLBACK_MS . ' ms',
            );

            return $fallback;
        }

        try {
            $interval = $env[EnvConstants::LOG_INDEX_PUSH_INTERVAL_MS]->int();
        } catch (EnvException $exception) {
            $this->trouble(
                'log index push environment is unreadable: ' . $exception->getMessage()
                . ', using the fallback of ' . LogSettingsCatalog::INDEX_PUSH_INTERVAL_FALLBACK_MS . ' ms',
            );

            return $fallback;
        }

        if ($interval < LogIndexPushIntervalRule::MINIMUM_MS) {
            $this->trouble(
                "log index push environment says {$interval} ms, below the minimum, using the fallback of "
                . LogSettingsCatalog::INDEX_PUSH_INTERVAL_FALLBACK_MS . ' ms',
            );

            return $fallback;
        }

        return $interval;
    }

    /**
     * Reads the takeout-undo window from the environment, falling back to the literal.
     *
     * A negative number is clamped to zero the way the numeric policies clamp theirs: below zero
     * the promise has no reading other than "no wait at all", which is what zero already says.
     *
     * @return int Window from the environment, or the fallback when it cannot answer
     */
    private function takeoutUndoWindowFromEnv(): int
    {
        $fallback = LogSettingsCatalog::TAKEOUT_UNDO_WINDOW_FALLBACK_SECONDS;
        $env = Hilos::$env;
        if ($env === null) {
            $this->trouble(
                'log takeout undo window is unreadable: no environment in this process, using the fallback of '
                . $fallback . ' s',
            );

            return $fallback;
        }

        try {
            return max(0, $env[EnvConstants::LOG_TAKEOUT_UNDO_WINDOW_SECONDS]->int());
        } catch (EnvException $exception) {
            $this->trouble(
                'log takeout undo window is unreadable: ' . $exception->getMessage()
                . ', using the fallback of ' . $fallback . ' s',
            );

            return $fallback;
        }
    }

    /**
     * Reads the free-space threshold from the environment, falling back to the literal.
     *
     * A negative number is clamped to zero the way the numeric policies clamp theirs: below zero
     * the threshold has no reading other than "no reserve at all", which is what zero already
     * says. A number above the ceiling is treated the way an unreadable one is, because a reserve
     * larger than the volume would have the screen say the room has run out forever.
     *
     * @return int Threshold from the environment, or the fallback when it cannot answer
     */
    private function freeSpaceThresholdFromEnv(): int
    {
        $fallback = LogSettingsCatalog::FREE_SPACE_THRESHOLD_FALLBACK_PERCENT;
        $env = Hilos::$env;
        if ($env === null) {
            $this->trouble(
                'log free space threshold is unreadable: no environment in this process, using the fallback of '
                . $fallback . '%',
            );

            return $fallback;
        }

        try {
            $threshold = max(0, $env[EnvConstants::LOG_FREE_SPACE_THRESHOLD_PERCENT]->int());
        } catch (EnvException $exception) {
            $this->trouble(
                'log free space threshold is unreadable: ' . $exception->getMessage()
                . ', using the fallback of ' . $fallback . '%',
            );

            return $fallback;
        }

        if ($threshold > LogFreeSpaceThresholdRule::MAXIMUM_PERCENT) {
            $this->trouble(
                "log free space threshold environment says {$threshold}%, above the maximum, using the fallback of "
                . $fallback . '%',
            );

            return $fallback;
        }

        return $threshold;
    }

    /**
     * Builds the retention policy from the environment, reporting the values it could not read.
     *
     * @return LogArchiveRetentionPolicy Policy from the environment, or one that keeps everything
     */
    private function retentionFromEnv(): LogArchiveRetentionPolicy
    {
        $policy = LogArchiveRetentionPolicy::fromEnv();
        foreach ($policy->unreadable as $key => $reason) {
            $this->trouble("{$key} is unreadable, nothing will be evicted ({$reason})");
        }

        return $policy;
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
            $this->complaints[] = 'Log settings: ' . $trouble;
        }

        $this->lastTrouble[$scope] = $trouble;
    }
}
