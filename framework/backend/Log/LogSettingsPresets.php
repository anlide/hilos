<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\TimeConstants;
use Hilos\Database\Settings\Preset\SettingPreset;
use Hilos\Database\Settings\Preset\SettingPresetGroup;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Utils\LogLevel;

/**
 * The three logging modes the Logs section offers (HIL-762).
 *
 * Frugal writes only what matters and keeps it a week; Normal is the middle the installation
 * starts on; Investigation writes everything and pays for it in space. The composition is the
 * owner's, taken from the Logs mockup of 27.08.2026, and this class is where it is written down —
 * the mechanism above it decides nothing about what a mode contains.
 *
 * Every preset names the same five keys, and the fifth is the one no card mentions: the age axis of
 * rotation, held at zero throughout. A card names exactly two axes — a schedule and a size — and an
 * age axis left switched on from an earlier configuration would rotate against what the card says.
 * A preset has to be a complete statement about its own subject or it is not a mode at all.
 *
 * Two keys of the log fragment are deliberately outside every preset. The batches always kept
 * ({@see LogSettingsCatalog::ARCHIVE_RETENTION_KEEP_BATCHES}) are a safety net rather than a
 * loudness, and the index push interval ({@see LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS}) is
 * transport between nodes and has nothing to do with how much a node writes.
 */
final class LogSettingsPresets implements SettingPresetGroupProviderInterface
{
    /** Machine key of the group, as it travels the wire and names the section. */
    public const string GROUP = 'logs';

    /** Writes only what matters and keeps it a week. */
    public const string FRUGAL = 'frugal';

    /** The middle the installation starts on. */
    public const string NORMAL = 'normal';

    /** Writes everything, and pays for it in space. */
    public const string INVESTIGATION = 'investigation';

    /** Bytes in one mebibyte, the unit the size axis of a preset is stated in. */
    private const int BYTES_PER_MEBIBYTE = 1024 * 1024;

    /** Schedule of the two calm modes: once a night, when nobody is reading. */
    private const string ROTATION_NIGHTLY = '0 3 * * *';

    /** Schedule of the loud mode: often enough that a live file stays readable. */
    private const string ROTATION_EVERY_SIX_HOURS = '0 */6 * * *';

    /**
     * Returns the logging modes in the order their cards are drawn.
     *
     * @return SettingPresetGroup Group of the Logs section
     */
    public static function presetGroup(): SettingPresetGroup
    {
        return new SettingPresetGroup(
            self::GROUP,
            LogSettingsCatalog::PRESET,
            [
                new SettingPreset(self::FRUGAL, [
                    LogSettingsCatalog::WRITE_LEVEL => LogLevel::Warning->value,
                    LogSettingsCatalog::ROTATION_CRON => self::ROTATION_NIGHTLY,
                    LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES => 256 * self::BYTES_PER_MEBIBYTE,
                    LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => 0,
                    LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS => 7 * TimeConstants::SECONDS_PER_DAY,
                ]),
                new SettingPreset(self::NORMAL, [
                    LogSettingsCatalog::WRITE_LEVEL => LogLevel::Info->value,
                    LogSettingsCatalog::ROTATION_CRON => self::ROTATION_NIGHTLY,
                    LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES => 512 * self::BYTES_PER_MEBIBYTE,
                    LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => 0,
                    LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS => 30 * TimeConstants::SECONDS_PER_DAY,
                ]),
                new SettingPreset(self::INVESTIGATION, [
                    LogSettingsCatalog::WRITE_LEVEL => LogLevel::Debug->value,
                    LogSettingsCatalog::ROTATION_CRON => self::ROTATION_EVERY_SIX_HOURS,
                    LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES => 1024 * self::BYTES_PER_MEBIBYTE,
                    LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => 0,
                    LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS => 90 * TimeConstants::SECONDS_PER_DAY,
                ]),
            ],
        );
    }
}
