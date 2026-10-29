import { applyServerTime } from '../../../src/session/serverClock.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'
import { describe, expect, it, vi } from 'vitest'

import {
  CHANGE_LOG_CHANNELS,
  CHANGE_LOG_NO_RECEIPT_TEXT,
  CHANGE_LOG_PERIODS,
  changeLogChannelBadge,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTouched,
  formatChangeLogTime,
  formatChangeLogWho,
  type HilosChangeLogPersonFields,
} from '../../../src/admin/changeLog/hilosChangeLog.js'
import { changeLogMutationBadge } from '../../../src/admin/changeLog/index.js'
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

  it('provides badge metadata for every mutation without changing its label', () => {
    expect(changeLogMutationBadge('create')).toEqual({
      label: 'created',
      icon: 'bi-plus-circle',
      tone: 'bg-success-subtle text-success-emphasis border border-success-subtle',
    })
    expect(changeLogMutationBadge('update')).toEqual({
      label: 'updated',
      icon: 'bi-pencil',
      tone: 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
    })
    expect(changeLogMutationBadge('delete')).toEqual({
      label: 'deleted',
      icon: 'bi-trash',
      tone: 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
    })
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

  it('shows local journal time and leaves an invalid timestamp unchanged', () => {
    const at = '2026-10-09T14:30:00.000Z'
    expect(formatChangeLogTime(at)).toBe(
      new Date(at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      }),
    )
    expect(formatChangeLogTime('not-a-time')).toBe('not-a-time')
  })

  it('labels every known channel and omits absent or unknown channels', () => {
    expect(
      ['web', 'migration', 'agent', 'cli', 'cron', 'mcp'].map((channel) =>
        changeLogChannelBadge(channel),
      ),
    ).toEqual([
      { label: 'Web', icon: 'bi-globe' },
      { label: 'Migration', icon: 'bi-database-up' },
      { label: 'Agent', icon: 'bi-cpu' },
      { label: 'CLI', icon: 'bi-terminal' },
      { label: 'Cron', icon: 'bi-alarm' },
      { label: 'MCP', icon: 'bi-robot' },
    ])
    expect(changeLogChannelBadge(null)).toBeNull()
    expect(changeLogChannelBadge('unknown')).toBeNull()
    expect(CHANGE_LOG_NO_RECEIPT_TEXT).toBe(
      'Changed past the application — no receipt',
    )
  })
})

it.each([-300_000, 300_000])(
  'displays absolute moments in the reader zone with clock drift %i',
  (drift) => {
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    applyServerTime(Date.now() + drift)
    try {
      expect(formatChangeLogTime('2026-01-01T00:30:05Z')).toBe(
        'Dec 31, 2025, 4:30:05 PM',
      )
      expect(formatChangeLogTime('not-a-time')).toBe('not-a-time')
      expect(formatChangeLogTime('2026-01-01T00:30:05')).toBe(
        '2026-01-01T00:30:05',
      )
    } finally {
      zone.mockRestore()
      applyServerTime(Date.now())
    }
  },
)
