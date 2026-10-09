import { describe, expect, it } from 'vitest'

import {
  CHANGE_LOG_CHANNELS,
  CHANGE_LOG_PERIODS,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTouched,
  formatChangeLogWho,
  type HilosChangeLogPersonFields,
} from '../../../src/admin/changeLog/hilosChangeLog.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

function person(
  overrides: Partial<HilosChangeLogPersonFields> = {},
): HilosChangeLogPersonFields {
  return {
    receiptId: 1,
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    channel: 'web',
    ...overrides,
  }
}

describe('change-log attribution and labels', () => {
  it('names people, guests, system work and bare entries', () => {
    expect(formatChangeLogWho(person())).toBe('Ada')
    expect(
      formatChangeLogWho(person({ actorId: 4, actorLabel: 'Deleted user #4' })),
    ).toBe('Deleted user #4')
    expect(
      formatChangeLogWho(person({ actorId: null, actorLabel: null })),
    ).toBe('Guest')
    expect(
      formatChangeLogWho(
        person({ actorId: null, actorLabel: null, channel: 'cron' }),
      ),
    ).toBe('System')
    expect(
      formatChangeLogWho(
        person({ actorId: null, actorLabel: null, receiptId: null }),
      ),
    ).toBe('Unknown')
  })

  it('keeps the hidden marker only for a present person', () => {
    expect(formatChangeLogWho(person({ actorLabel: HIDDEN_VALUE }))).toBe(
      HIDDEN_VALUE,
    )
    expect(
      formatChangeLogWho(person({ actorId: null, actorLabel: HIDDEN_VALUE })),
    ).toBe('Guest')
    expect(
      formatChangeLogOnBehalfOf(
        person({ subjectId: 9, subjectLabel: HIDDEN_VALUE }),
      ),
    ).toBe(HIDDEN_VALUE)
    expect(
      formatChangeLogOnBehalfOf(
        person({ subjectId: null, subjectLabel: HIDDEN_VALUE }),
      ),
    ).toBeNull()
  })

  it('formats record keys, mutations and first-touch summaries', () => {
    expect(formatChangeLogRecordKey([3])).toBe('#3')
    expect(formatChangeLogRecordKey([1, 'en'])).toBe('#1, en')
    expect(formatChangeLogRecordKey(null)).toBe('#?')
    expect(formatChangeLogRecordKey([null])).toBe('#?')
    expect(formatChangeLogMutation('create')).toBe('created')
    expect(formatChangeLogMutation('update')).toBe('updated')
    expect(formatChangeLogMutation('delete')).toBe('deleted')
    expect(
      formatChangeLogTouched([
        {
          table: 'bot',
          records: 1,
          recordKey: [3],
          mutation: 'update',
          changedFields: 2,
        },
        {
          table: 'hilos_user',
          records: 3,
          recordKey: null,
          mutation: null,
          changedFields: null,
        },
      ]),
    ).toBe('bot #3 · updated · 2 fields, hilos_user · 3 records')
    expect(
      formatChangeLogTouched([
        {
          table: 'bot',
          records: 1,
          recordKey: null,
          mutation: 'update',
          changedFields: 1,
        },
      ]),
    ).toBe('bot #? · updated · 1 field')
  })

  it('offers only the agreed channels and periods', () => {
    expect(CHANGE_LOG_CHANNELS.map(({ value }) => value)).toEqual([
      'web',
      'migration',
      'agent',
      'cli',
      'cron',
      'mcp',
    ])
    expect(CHANGE_LOG_PERIODS.map(({ value }) => value)).toEqual([
      'hour',
      'day',
      'week',
      'month',
    ])
  })
})
