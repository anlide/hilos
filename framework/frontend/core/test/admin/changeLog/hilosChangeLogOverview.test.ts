import { describe, expect, it } from 'vitest'

import {
  CHANGE_LOG_OPEN_TABLES_LABEL,
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogFeedEmpty,
  changeLogJournalNote,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  readHilosChangeLogOverview,
  type HilosChangeLogOverview,
} from '../../../src/admin/changeLog/hilosChangeLogOverview.js'

const overview: HilosChangeLogOverview = {
  journalEntries: 1234,
  journalBytes: 2048,
  oldestAt: '2026-01-15T12:00:00.000Z',
  journaledTables: ['bot', 'hilos_user'],
  liveTables: 10,
}

describe('change-log overview', () => {
  it('reads a valid page-data section and rejects malformed or missing data', () => {
    expect(readHilosChangeLogOverview(overview)).toEqual(overview)
    expect(
      readHilosChangeLogOverview({ ...overview, journalBytes: '2048' }),
    ).toBeNull()
    expect(
      readHilosChangeLogOverview({ ...overview, journaledTables: 2 }),
    ).toBeNull()
    expect(readHilosChangeLogOverview(null)).toBeNull()
  })

  it('formats both tiles and keeps the section wording in the core', () => {
    expect(CHANGE_LOG_OVERVIEW_SECTION).toBe('changeLogOverview')
    expect(CHANGE_LOG_TILE_JOURNAL_LABEL).toBe('In the journal database')
    expect(CHANGE_LOG_TILE_TRACKED_LABEL).toBe('Tracked')
    expect(CHANGE_LOG_TILE_TRACKED_NOTE).toBe('tables with the journal label')
    expect(CHANGE_LOG_OPEN_TABLES_LABEL).toBe('Open tables')
    expect(CHANGE_LOG_SECTION_NOTE).toContain(
      'there is nothing to switch on or off here.',
    )
    expect(formatChangeLogEntries(overview.journalEntries)).toBe(
      new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
      }).format(1234),
    )
    expect(changeLogJournalNote(overview)).toBe(
      `2.0 KB · since ${new Date('2026-01-15T12:00:00.000Z').toLocaleDateString(
        undefined,
        {
          month: 'long',
          year: 'numeric',
        },
      )}`,
    )
    expect(formatChangeLogCoverage(overview)).toBe('2 of 10')
  })

  it('shows the empty-journal note and the two unfiltered feed states', () => {
    const empty = { ...overview, oldestAt: null, journalEntries: 0 }
    expect(changeLogJournalNote(empty)).toBe('nothing recorded yet')
    expect(changeLogFeedEmpty({ ...empty, journaledTables: [] })).toEqual({
      kind: 'untracked',
      title: 'No table is tracked',
      hint: 'No entity carries the journal label, so no journal triggers exist and nothing is written.',
    })
    const recorded = {
      kind: 'empty',
      title: 'Nothing recorded yet',
      hint: 'The tracked tables have not changed since the journal started.',
    }
    expect(changeLogFeedEmpty(empty)).toEqual(recorded)
    expect(changeLogFeedEmpty(null)).toEqual(recorded)
  })
})
