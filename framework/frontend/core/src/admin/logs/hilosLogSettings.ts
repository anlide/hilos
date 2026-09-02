// The Logs section's half of the setting-presets screen: its signal, the keys of the
// settings its modes write, and the vocabulary that turns those values into the
// sentences the cards and the differences are read as. It is framework-agnostic
// (imports no UI framework) and reads only @hilos/core primitives, so the
// Vue/React/Angular logging-mode views stay thin (multiframework-core.md).
//
// The layout, the states and the behavior are the mechanism's
// (admin/settings/hilosSettingPresets.ts); everything here is what "logs" means. A
// second section growing presets fills in its own module of this shape and touches
// nothing of the common one.
//
// Nothing is guessed. A preset name the recipe grew after this file was written comes
// out as itself, and so does a difference on a setting key nobody here knows: the
// backend recipe is free to move first, and a screen that answered such a frame with
// silence or a crash would make it unfree.

import {
  settingPresetsSignalSchemas,
  type HilosSettingPresetDifference,
  type HilosSettingPresetsVocabulary,
} from '../settings/hilosSettingPresets.js'
import { HilosPages } from '../../routing/hilosPages.js'

/** Server→client signal `type` carrying the group (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS`). */
export const LOG_SETTINGS_SIGNAL = 'subscription_page_hilos_logs_settings'

/**
 * The logging-mode signal schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the frames the screen ingests. {@link createHilosConnection}
 * merges it in, so a project never restates it.
 *
 * A set of its own rather than an entry in a neighbouring log screen's: two
 * independent screens must not depend on which of them landed first.
 */
export const LOGS_SETTINGS_SIGNAL_SCHEMAS =
  settingPresetsSignalSchemas(LOG_SETTINGS_SIGNAL)

// The setting keys the logging modes write, mirroring the backend catalog
// (LogSettingsCatalog). They are declared here because this module is the one that
// knows what each of them means.

/** Setting key: how much is written at all. */
export const LOG_SETTING_WRITE_LEVEL = 'logs.write_level'

/** Setting key: the schedule the live files are rotated on. */
export const LOG_SETTING_ROTATION_CRON = 'logs.rotation.cron'

/** Setting key: the size one live file may reach before it is rotated. */
export const LOG_SETTING_ROTATION_MAX_LIVE_SIZE =
  'logs.rotation.max_live_size_bytes'

/** Setting key: the age since the last rotation that forces the next one. */
export const LOG_SETTING_ROTATION_MAX_AGE = 'logs.rotation.max_age_seconds'

/** Setting key: how long an archived batch is kept. */
export const LOG_SETTING_RETENTION_MAX_AGE =
  'logs.archive_retention.max_age_seconds'

/** Preset name: writes only what matters and keeps it a week. */
export const LOG_PRESET_FRUGAL = 'frugal'

/** Preset name: the middle an installation starts on. */
export const LOG_PRESET_NORMAL = 'normal'

/** Preset name: writes everything, and pays for it in space. */
export const LOG_PRESET_INVESTIGATION = 'investigation'

/** The log level that writes everything, which is the one the wording singles out. */
const LOG_LEVEL_DEBUG = 'DEBUG'

/** Seconds in one day, the unit the retention axis is read in. */
const SECONDS_PER_DAY = 86400

/** Seconds in one hour, the unit a forced-rotation age is read in. */
const SECONDS_PER_HOUR = 3600

/** Bytes in one mebibyte, the smallest unit a size threshold is read in. */
const BYTES_PER_MEBIBYTE = 1024 * 1024

/** Mebibytes from which a size threshold is read in gibibytes instead. */
const MEBIBYTES_PER_GIBIBYTE = 1024

/** A cron expression of five fields whose last three are unrestricted. */
const DAILY_CRON = /^(\d{1,2}) (\d{1,2}) \* \* \*$/

/** A cron expression running every N-th hour, at a fixed minute of it. */
const EVERY_N_HOURS_CRON = /^\d{1,2} \*\/(\d{1,2}) \* \* \*$/

/** The title, subtitle and icon of each mode the recipe declares. */
const LOG_PRESET_CARDS: Record<
  string,
  { title: string; subtitle: string; icon: string }
> = {
  [LOG_PRESET_FRUGAL]: {
    title: 'Frugal',
    subtitle: 'Writes only what matters and keeps it a week.',
    icon: 'bi-feather',
  },
  [LOG_PRESET_NORMAL]: {
    title: 'Normal',
    subtitle: 'The middle an installation starts on.',
    icon: 'bi-journal-text',
  },
  [LOG_PRESET_INVESTIGATION]: {
    title: 'Investigation',
    subtitle: 'Looking for a cause, and ready to pay for it in space.',
    icon: 'bi-bug',
  },
}

/** The icon of a mode this file has never heard of. */
const UNKNOWN_PRESET_ICON = 'bi-gear'

/**
 * A count and the word it counts, in agreement.
 *
 * @param count How many.
 * @param noun The singular of what is counted.
 */
function counted(count: number, noun: string): string {
  return `${count} ${noun}${count === 1 ? '' : 's'}`
}

/**
 * A wire value as a string, whatever arrived.
 *
 * A value of an unexpected type is printed rather than dropped: the catalog on the
 * backend decides what a setting holds, and a screen that hid what it did not
 * recognize would hide exactly the case worth seeing.
 *
 * @param value The value as it arrived on the wire.
 */
function raw(value: unknown): string {
  return typeof value === 'string' ? value : String(value)
}

/**
 * How much is written, as the tail of a sentence beginning "writes".
 *
 * @param value Value of {@link LOG_SETTING_WRITE_LEVEL}: a {@link LogLevel} name.
 */
export function formatLogWriteLevel(value: unknown): string {
  if (typeof value !== 'string') {
    return raw(value)
  }

  return value === LOG_LEVEL_DEBUG
    ? 'everything, including DEBUG'
    : `from ${value} and worse`
}

/**
 * When the live files are rotated, as the tail of a sentence beginning "rotates".
 *
 * Two forms are read, because the recipe declares two: a fixed time of day and every
 * N-th hour. Anything else is printed as the expression itself — the frontend keeps
 * no cron parser, since an invented one would be wrong confidently.
 *
 * @param value Value of {@link LOG_SETTING_ROTATION_CRON}: a five-field cron expression.
 */
export function formatLogRotationSchedule(value: unknown): string {
  if (typeof value !== 'string') {
    return raw(value)
  }

  const daily = DAILY_CRON.exec(value)
  if (daily) {
    const hour = daily[2].padStart(2, '0')
    const minute = daily[1].padStart(2, '0')

    return `at ${hour}:${minute}`
  }

  const hourly = EVERY_N_HOURS_CRON.exec(value)
  if (hourly) {
    const every = Number(hourly[1])

    return every === 1 ? 'every hour' : `every ${every} hours`
  }

  return value
}

/**
 * The size one live file may reach, in the largest unit it is whole in.
 *
 * @param value Value of {@link LOG_SETTING_ROTATION_MAX_LIVE_SIZE}, in bytes.
 */
export function formatLogSizeThreshold(value: unknown): string {
  if (typeof value !== 'number') {
    return raw(value)
  }

  const mebibytes = value / BYTES_PER_MEBIBYTE
  if (mebibytes < MEBIBYTES_PER_GIBIBYTE) {
    return `${trimmedNumber(mebibytes)} MiB`
  }

  return `${trimmedNumber(mebibytes / MEBIBYTES_PER_GIBIBYTE)} GiB`
}

/**
 * How long an archived batch is kept, in days.
 *
 * @param value Value of {@link LOG_SETTING_RETENTION_MAX_AGE}, in seconds.
 */
export function formatLogRetentionDays(value: unknown): string {
  if (typeof value !== 'number') {
    return raw(value)
  }

  return counted(trimmedNumber(value / SECONDS_PER_DAY), 'day')
}

/**
 * The age since the last rotation that forces the next one, in hours.
 *
 * Not a line on any card: every mode declares this axis switched off, so the card
 * would name an empty place. It is only ever read as a difference, when somebody has
 * switched it on by hand.
 *
 * @param value Value of {@link LOG_SETTING_ROTATION_MAX_AGE}, in seconds.
 */
function formatLogRotationAge(value: unknown): string {
  if (typeof value !== 'number') {
    return raw(value)
  }

  return counted(trimmedNumber(value / SECONDS_PER_HOUR), 'hour')
}

/**
 * A quotient rounded to one decimal, so a value the recipe states in whole units
 * stays whole and a hand-typed one does not turn into a screenful of digits.
 *
 * @param value The quotient.
 */
function trimmedNumber(value: number): number {
  return Math.round(value * 10) / 10
}

/**
 * One difference on a key this vocabulary knows nothing about, in raw values.
 *
 * @param difference The setting key and the two values that disagree on it.
 */
function rawDifference(difference: HilosSettingPresetDifference): string {
  return `${difference.key}: ${raw(difference.currentValue)} instead of ${raw(
    difference.presetValue,
  )}`
}

/** Everything the Logs section says about its own logging modes. */
export const hilosLogSettingsVocabulary: HilosSettingPresetsVocabulary = {
  intro:
    'A mode is a set of real values: how much is written, when the live files are ' +
    'rotated, at what size, and how long a batch is kept. Choose a mode and all of ' +
    'them are rewritten at once. Edit one of them on its own in the general settings ' +
    'and the mode stays chosen — it just says, inside its own card, what your set ' +
    'differs in.',
  groupHeading: 'Logging mode',
  differencesHeading: 'Differences:',
  revertLabel: "Put the mode's values back",
  footnote:
    'The differences live inside the mode itself rather than in a strip above it: ' +
    'the set is still the one you chose, just with a caveat. That way both where you ' +
    'started and where you went are visible, and one button takes you back.',
  generalSettingsTitle: 'The same values in the general settings',
  generalSettingsLead:
    'One at a time and without the couplings: convenient to compare, not to decide ' +
    'by. Edits made there are what the differences above are.',
  generalSettingsLabel: 'Open',
  generalSettingsPage: HilosPages.SETTINGS,
  unknownSelectionNote:
    'The mode stored for this section is not one this installation offers any more ' +
    '— it was renamed or dropped after somebody applied it. Nothing is highlighted ' +
    'below; choosing any mode repairs it.',
  confirmTitle: 'Overwrite your own edits?',
  confirmBody(title) {
    return `${title} writes all of its values, and the settings you changed by hand go with them.`
  },
  confirmLabel(title) {
    return `Apply ${title}`
  },
  presetTitle(name) {
    return LOG_PRESET_CARDS[name]?.title ?? name
  },
  presetSubtitle(name) {
    return LOG_PRESET_CARDS[name]?.subtitle ?? ''
  },
  presetIcon(name) {
    return LOG_PRESET_CARDS[name]?.icon ?? UNKNOWN_PRESET_ICON
  },
  valueLines(values) {
    const lines: string[] = []
    if (LOG_SETTING_WRITE_LEVEL in values) {
      lines.push(
        `writes ${formatLogWriteLevel(values[LOG_SETTING_WRITE_LEVEL])}`,
      )
    }
    if (
      LOG_SETTING_ROTATION_CRON in values &&
      LOG_SETTING_ROTATION_MAX_LIVE_SIZE in values
    ) {
      const schedule = formatLogRotationSchedule(
        values[LOG_SETTING_ROTATION_CRON],
      )
      const size = formatLogSizeThreshold(
        values[LOG_SETTING_ROTATION_MAX_LIVE_SIZE],
      )
      lines.push(`rotates ${schedule} or at ${size}`)
    }
    if (LOG_SETTING_RETENTION_MAX_AGE in values) {
      const kept = formatLogRetentionDays(values[LOG_SETTING_RETENTION_MAX_AGE])
      lines.push(`keeps batches for ${kept}`)
    }

    // LOG_SETTING_ROTATION_MAX_AGE is deliberately absent: every mode declares that
    // axis off, and naming an axis nobody uses is showing an empty place.
    return lines
  },
  differenceLine(difference) {
    switch (difference.key) {
      case LOG_SETTING_WRITE_LEVEL: {
        const current = formatLogWriteLevel(difference.currentValue)
        // "everything, including DEBUG" ends in an aside, and English closes one
        // before the clause that follows it.
        const closing = difference.currentValue === LOG_LEVEL_DEBUG ? ',' : ''
        const declared = formatLogWriteLevel(difference.presetValue)

        return `writes ${current}${closing} instead of ${declared}`
      }
      case LOG_SETTING_ROTATION_CRON: {
        const current = formatLogRotationSchedule(difference.currentValue)
        const declared = formatLogRotationSchedule(difference.presetValue)

        return `rotates ${current} instead of ${declared}`
      }
      case LOG_SETTING_ROTATION_MAX_LIVE_SIZE: {
        const current = formatLogSizeThreshold(difference.currentValue)
        const declared = formatLogSizeThreshold(difference.presetValue)

        return `rotates at ${current} instead of at ${declared}`
      }
      case LOG_SETTING_RETENTION_MAX_AGE: {
        const current = formatLogRetentionDays(difference.currentValue)
        const declared = formatLogRetentionDays(difference.presetValue)

        return `keeps batches for ${current} instead of ${declared}`
      }
      case LOG_SETTING_ROTATION_MAX_AGE: {
        // Only one direction is possible: a preset always declares this axis off, so
        // the drift is always somebody having switched it on.
        const current = formatLogRotationAge(difference.currentValue)

        return `also rotates ${current} after the last rotation, which the mode does not do`
      }
      default:
        return rawDifference(difference)
    }
  },
}
