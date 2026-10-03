<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Reads the live retention and schedule of spent verification codes (HIL-1163). */
final class VerificationSweepSettings
{
    public const string RETENTION_SECONDS_KEY = 'auth.verification.retention_seconds';
    public const string SWEEP_CRON_KEY = 'auth.verification.sweep_cron';
    public const string DEFAULT_SWEEP_CRON = '*/10 * * * *';
    public const int SEND_WINDOW_FALLBACK_SECONDS = 3600;

    /**
     * @return int The node's send-count window in seconds
     * @throws EnvException When the configured environment value is invalid
     */
    public static function sendWindowSeconds(): int
    {
        return Hilos::$env === null
            ? self::SEND_WINDOW_FALLBACK_SECONDS
            : Hilos::$env[EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC]->int();
    }

    /**
     * @return int Retention in seconds, never shorter than the send-count window
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the catalog or stored value is invalid
     * @throws EnvException When the send window is invalid
     */
    public static function retentionSeconds(): int
    {
        $window = self::sendWindowSeconds();
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[self::RETENTION_SECONDS_KEY])) {
            return $window;
        }

        return max($window, $settings[self::RETENTION_SECONDS_KEY]->int());
    }

    /**
     * @return string Runnable five-field cron expression
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the catalog or stored value is invalid
     */
    public static function sweepCron(): string
    {
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[self::SWEEP_CRON_KEY])) {
            return self::DEFAULT_SWEEP_CRON;
        }

        $expression = $settings[self::SWEEP_CRON_KEY]->string();

        return trim($expression) !== '' && CronRule::isValidExpression($expression)
            ? $expression
            : self::DEFAULT_SWEEP_CRON;
    }
}
