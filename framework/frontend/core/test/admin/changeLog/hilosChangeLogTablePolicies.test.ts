import { describe, expect, it } from 'vitest'

import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_NOISY_COLUMNS_TITLE,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  changeLogNoisyColumnsSchema,
  readHilosChangeLogNoisyColumns,
  type HilosChangeLogNoisyColumn,
} from '../../../src/admin/changeLog/hilosChangeLogTablePolicies.js'

const noisyColumns: readonly HilosChangeLogNoisyColumn[] = [
  {
    table: 'bot',
    column: 'last_activity',
    reason: '  Automatic activity stamp — not a decision.\n  ',
  },
  {
    table: 'hilos_user',
    column: 'last_activity',
    reason: '<last_activity> changes on every visit',
  },
]

describe('change-log noisy-column snapshot', () => {
  it('reads every declaration without trimming or transforming its reason', () => {
    expect(readHilosChangeLogNoisyColumns(noisyColumns)).toEqual(noisyColumns)
    expect(changeLogNoisyColumnsSchema.parse(noisyColumns)).toEqual(
      noisyColumns,
    )
  })

  it('keeps a valid empty snapshot distinct from malformed data', () => {
    expect(readHilosChangeLogNoisyColumns([])).toEqual([])
    expect(readHilosChangeLogNoisyColumns(undefined)).toBeNull()
    expect(CHANGE_LOG_NOISY_COLUMNS_EMPTY).toBe(
      'No noisy columns are declared on tracked tables',
    )
  })

  it.each([null, false, 42, 'noisy columns', {}, { columns: [] }])(
    'rejects a malformed section %j',
    (raw) => {
      expect(readHilosChangeLogNoisyColumns(raw)).toBeNull()
    },
  )

  it.each([null, [], 'column', 42])('rejects a malformed row %j', (row) => {
    expect(readHilosChangeLogNoisyColumns([row])).toBeNull()
  })

  it.each(['table', 'column', 'reason'])(
    'requires a nonempty string for %s',
    (field) => {
      const row: Record<string, unknown> = {
        table: 'hilos_user',
        column: 'last_activity',
        reason: 'Automatic activity stamp',
      }
      for (const invalid of [undefined, null, '', 42, false]) {
        expect(
          readHilosChangeLogNoisyColumns([{ ...row, [field]: invalid }]),
        ).toBeNull()
      }
      delete row[field]
      expect(readHilosChangeLogNoisyColumns([row])).toBeNull()
    },
  )

  it('refuses the whole snapshot when one declaration is malformed', () => {
    expect(
      readHilosChangeLogNoisyColumns([
        noisyColumns[0],
        { table: 'hilos_user', column: 'last_activity', reason: null },
      ]),
    ).toBeNull()
  })
})

describe('change-log table policies reference', () => {
  it('owns the section keys, headings, labels, and notes in the core', () => {
    expect(CHANGE_LOG_NOISY_COLUMNS_SECTION).toBe('changeLogNoisyColumns')
    expect(CHANGE_LOG_NOISY_COLUMNS_TITLE).toBe('Noisy columns')
    expect(CHANGE_LOG_COLUMN_MODES_TITLE).toBe(
      'How a column enters the journal',
    )
    expect(CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL).toBe('Column')
    expect(CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL).toBe('Why noisy')
    expect(CHANGE_LOG_TABLES_OPEN_LABEL).toBe('Open')
    expect(CHANGE_LOG_TABLES_NOTE).toContain(
      'The entity declares whether its table is journaled',
    )
    expect(CHANGE_LOG_TABLES_NOTE).toContain('there is nothing to change here')
    expect(CHANGE_LOG_NOISY_COLUMNS_NOTE).toContain('drown out real changes')
    expect(CHANGE_LOG_NOISY_COLUMNS_NOTE).toContain(
      'An update touching only noisy columns is not journaled at all',
    )
    expect(CHANGE_LOG_NOISY_COLUMNS_NOTE).toContain('may have no journal label')
  })

  it('lists exactly the six phase-1 modes, including binary', () => {
    expect(CHANGE_LOG_COLUMN_MODES.map((mode) => mode.key)).toEqual([
      'value',
      'personal',
      'secret',
      'noise',
      'binary',
      'record_key',
    ])
    for (const mode of CHANGE_LOG_COLUMN_MODES) {
      expect(mode.label).not.toBe('')
      expect(mode.icon).toMatch(/^bi-/)
      expect(mode.tone).not.toBe('')
      expect(mode.description).not.toBe('')
      expect(mode.description).not.toMatch(/custom trigger/i)
    }
  })

  it('explains values, facts, omissions, and the record identity', () => {
    const [value, personal, secret, noise, binary, key] =
      CHANGE_LOG_COLUMN_MODES
    expect(value.description).toContain('old and new values')
    expect(value.description).toContain('Numbers, short strings, and dates')
    expect(value.description).toContain('long text and JSON')
    expect(value.description).toContain('neighboring journal table')
    expect(personal.description).toContain('only the fact')
    expect(personal.description).toContain('never its value')
    expect(secret.description).toContain('neither their values nor the fact')
    expect(noise.description).toContain('produces no journal row')
    expect(binary.description).toContain('only the fact')
    expect(binary.description).toContain('never its binary value')
    expect(key.description).toContain('identifies the record')
    expect(key.description).toContain('not recorded as a separate field change')
  })
})
