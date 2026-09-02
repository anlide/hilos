import { describe, expect, it } from 'vitest'

import {
  formatLogRetentionDays,
  formatLogRotationSchedule,
  formatLogSizeThreshold,
  formatLogWriteLevel,
  hilosLogSettingsVocabulary,
  LOG_PRESET_FRUGAL,
  LOG_PRESET_INVESTIGATION,
  LOG_PRESET_NORMAL,
  LOG_SETTING_RETENTION_MAX_AGE,
  LOG_SETTING_ROTATION_CRON,
  LOG_SETTING_ROTATION_MAX_AGE,
  LOG_SETTING_ROTATION_MAX_LIVE_SIZE,
  LOG_SETTING_WRITE_LEVEL,
  LOGS_SETTINGS_SIGNAL_SCHEMAS,
  LOG_SETTINGS_SIGNAL,
} from '../../../src/admin/logs/hilosLogSettings.js'

const MIB = 1024 * 1024
const DAY = 86400

/** The three modes exactly as the backend recipe declares them (LogSettingsPresets). */
const RECIPE: Record<string, Record<string, unknown>> = {
  [LOG_PRESET_FRUGAL]: {
    [LOG_SETTING_WRITE_LEVEL]: 'WARNING',
    [LOG_SETTING_ROTATION_CRON]: '0 3 * * *',
    [LOG_SETTING_ROTATION_MAX_LIVE_SIZE]: 256 * MIB,
    [LOG_SETTING_ROTATION_MAX_AGE]: 0,
    [LOG_SETTING_RETENTION_MAX_AGE]: 7 * DAY,
  },
  [LOG_PRESET_NORMAL]: {
    [LOG_SETTING_WRITE_LEVEL]: 'INFO',
    [LOG_SETTING_ROTATION_CRON]: '0 3 * * *',
    [LOG_SETTING_ROTATION_MAX_LIVE_SIZE]: 512 * MIB,
    [LOG_SETTING_ROTATION_MAX_AGE]: 0,
    [LOG_SETTING_RETENTION_MAX_AGE]: 30 * DAY,
  },
  [LOG_PRESET_INVESTIGATION]: {
    [LOG_SETTING_WRITE_LEVEL]: 'DEBUG',
    [LOG_SETTING_ROTATION_CRON]: '0 */6 * * *',
    [LOG_SETTING_ROTATION_MAX_LIVE_SIZE]: 1024 * MIB,
    [LOG_SETTING_ROTATION_MAX_AGE]: 0,
    [LOG_SETTING_RETENTION_MAX_AGE]: 90 * DAY,
  },
}

describe('LOGS_SETTINGS_SIGNAL_SCHEMAS', () => {
  it('is a set of its own under the section signal', () => {
    expect(Object.keys(LOGS_SETTINGS_SIGNAL_SCHEMAS)).toEqual([
      LOG_SETTINGS_SIGNAL,
    ])
    expect(LOG_SETTINGS_SIGNAL).toBe('subscription_page_hilos_logs_settings')
  })
})

describe('formatLogWriteLevel', () => {
  it('singles out the level that writes everything', () => {
    expect(formatLogWriteLevel('DEBUG')).toBe('everything, including DEBUG')
  })

  it('reads every other level as a floor', () => {
    expect(formatLogWriteLevel('INFO')).toBe('from INFO and worse')
    expect(formatLogWriteLevel('WARNING')).toBe('from WARNING and worse')
    expect(formatLogWriteLevel('ERROR')).toBe('from ERROR and worse')
  })
})

describe('formatLogRotationSchedule', () => {
  it('reads a nightly expression as a time of day', () => {
    expect(formatLogRotationSchedule('0 3 * * *')).toBe('at 03:00')
    expect(formatLogRotationSchedule('30 21 * * *')).toBe('at 21:30')
  })

  it('reads an every-N-th-hour expression as an interval', () => {
    expect(formatLogRotationSchedule('0 */6 * * *')).toBe('every 6 hours')
    expect(formatLogRotationSchedule('0 */1 * * *')).toBe('every hour')
  })

  it('prints anything else as itself rather than guessing at it', () => {
    expect(formatLogRotationSchedule('15 4 * * 0')).toBe('15 4 * * 0')
    expect(formatLogRotationSchedule('*/5 * * * *')).toBe('*/5 * * * *')
  })
})

describe('formatLogSizeThreshold', () => {
  it('reads a threshold in mebibytes until a gibibyte is whole', () => {
    expect(formatLogSizeThreshold(256 * MIB)).toBe('256 MiB')
    expect(formatLogSizeThreshold(512 * MIB)).toBe('512 MiB')
  })

  it('reads a gibibyte as one, without a fractional part it does not have', () => {
    expect(formatLogSizeThreshold(1024 * MIB)).toBe('1 GiB')
    expect(formatLogSizeThreshold(2048 * MIB)).toBe('2 GiB')
  })
})

describe('formatLogRetentionDays', () => {
  it('counts days and agrees with the count', () => {
    expect(formatLogRetentionDays(7 * DAY)).toBe('7 days')
    expect(formatLogRetentionDays(30 * DAY)).toBe('30 days')
    expect(formatLogRetentionDays(DAY)).toBe('1 day')
  })
})

describe('hilosLogSettingsVocabulary.valueLines', () => {
  it('draws the three lines of the frugal card out of its values', () => {
    expect(hilosLogSettingsVocabulary.valueLines(RECIPE.frugal)).toEqual([
      'writes from WARNING and worse',
      'rotates at 03:00 or at 256 MiB',
      'keeps batches for 7 days',
    ])
  })

  it('draws the three lines of the normal card out of its values', () => {
    expect(hilosLogSettingsVocabulary.valueLines(RECIPE.normal)).toEqual([
      'writes from INFO and worse',
      'rotates at 03:00 or at 512 MiB',
      'keeps batches for 30 days',
    ])
  })

  it('draws the three lines of the investigation card out of its values', () => {
    expect(hilosLogSettingsVocabulary.valueLines(RECIPE.investigation)).toEqual(
      [
        'writes everything, including DEBUG',
        'rotates every 6 hours or at 1 GiB',
        'keeps batches for 90 days',
      ],
    )
  })

  it('names no line for the axis every mode declares off', () => {
    for (const values of Object.values(RECIPE)) {
      expect(
        hilosLogSettingsVocabulary
          .valueLines(values)
          .some((line) => line.includes('after the last rotation')),
      ).toBe(false)
    }
  })
})

describe('hilosLogSettingsVocabulary.differenceLine', () => {
  it('reads a drift of the write level', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_WRITE_LEVEL,
        presetValue: 'INFO',
        currentValue: 'DEBUG',
      }),
    ).toBe('writes everything, including DEBUG, instead of from INFO and worse')
  })

  it('reads a drift of the write level the other way round', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_WRITE_LEVEL,
        presetValue: 'DEBUG',
        currentValue: 'ERROR',
      }),
    ).toBe('writes from ERROR and worse instead of everything, including DEBUG')
  })

  it('reads a drift of the rotation schedule', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_ROTATION_CRON,
        presetValue: '0 3 * * *',
        currentValue: '0 */6 * * *',
      }),
    ).toBe('rotates every 6 hours instead of at 03:00')
  })

  it('reads a drift of the size threshold', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_ROTATION_MAX_LIVE_SIZE,
        presetValue: 512 * MIB,
        currentValue: 1024 * MIB,
      }),
    ).toBe('rotates at 1 GiB instead of at 512 MiB')
  })

  it('reads a drift of the retention', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_RETENTION_MAX_AGE,
        presetValue: 30 * DAY,
        currentValue: 14 * DAY,
      }),
    ).toBe('keeps batches for 14 days instead of 30 days')
  })

  it('reads the axis a mode never declares as switched on by hand', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: LOG_SETTING_ROTATION_MAX_AGE,
        presetValue: 0,
        currentValue: 12 * 3600,
      }),
    ).toBe(
      'also rotates 12 hours after the last rotation, which the mode does not do',
    )
  })

  it('gives a key it has never heard of its raw values rather than silence', () => {
    expect(
      hilosLogSettingsVocabulary.differenceLine({
        key: 'logs.something.new',
        presetValue: 5,
        currentValue: 9,
      }),
    ).toBe('logs.something.new: 9 instead of 5')
  })
})

describe('hilosLogSettingsVocabulary card wording', () => {
  it('titles, subtitles and pictures each of the three modes', () => {
    expect(hilosLogSettingsVocabulary.presetTitle(LOG_PRESET_FRUGAL)).toBe(
      'Frugal',
    )
    expect(hilosLogSettingsVocabulary.presetTitle(LOG_PRESET_NORMAL)).toBe(
      'Normal',
    )
    expect(
      hilosLogSettingsVocabulary.presetTitle(LOG_PRESET_INVESTIGATION),
    ).toBe('Investigation')
    expect(hilosLogSettingsVocabulary.presetSubtitle(LOG_PRESET_FRUGAL)).toBe(
      'Writes only what matters and keeps it a week.',
    )
    expect(
      hilosLogSettingsVocabulary.presetIcon(LOG_PRESET_INVESTIGATION),
    ).toBe('bi-bug')
  })

  it('gives a mode it has never heard of its own name back', () => {
    expect(hilosLogSettingsVocabulary.presetTitle('forensic')).toBe('forensic')
    expect(hilosLogSettingsVocabulary.presetSubtitle('forensic')).toBe('')
    expect(hilosLogSettingsVocabulary.presetIcon('forensic')).toBe('bi-gear')
  })

  it('builds the confirmation out of the title of the mode about to be written', () => {
    expect(hilosLogSettingsVocabulary.confirmLabel('Frugal')).toBe(
      'Apply Frugal',
    )
    expect(hilosLogSettingsVocabulary.confirmBody('Frugal')).toBe(
      'Frugal writes all of its values, and the settings you changed by hand go with them.',
    )
  })
})
