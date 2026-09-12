import { describe, expect, it } from 'vitest'
import { type HilosTableColumn } from '../../src/table/hilosTableColumn.js'
import { type TableViewportRow } from '../../src/table/TableViewportController.js'
import {
  TABLE_STALENESS_COPY,
  hilosTableStaleColumns,
  hilosTableStaleLabel,
  hilosTableStaleSources,
} from '../../src/table/tableStaleness.js'

function makeRow(
  rowKey: string,
  staleSources: readonly string[],
  placeholder = false,
): TableViewportRow<unknown> {
  return {
    rowKey,
    row: placeholder ? null : {},
    placeholder,
    pending: null,
    highlighted: false,
    selected: false,
    staleSources,
  }
}

const columns: readonly HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'presence', label: 'Presence', source: 'connections' },
  { key: 'onlineSessionCount', label: 'Sessions', source: 'connections' },
  { key: 'size', label: 'Size', source: 'volumes' },
]

describe('hilosTableStaleSources', () => {
  it('unites the sources named anywhere in the window', () => {
    const sources = hilosTableStaleSources([
      makeRow('a', ['connections']),
      makeRow('b', []),
      makeRow('c', ['volumes', 'connections']),
    ])

    expect([...sources].sort()).toEqual(['connections', 'volumes'])
  })

  it('is empty when every shown row is current', () => {
    const sources = hilosTableStaleSources([makeRow('a', []), makeRow('b', [])])

    expect(sources.size).toBe(0)
  })

  it('does not count a placeholder left by a removed row', () => {
    const sources = hilosTableStaleSources([
      makeRow('a', ['connections'], true),
      makeRow('b', []),
    ])

    expect(sources.size).toBe(0)
  })
})

describe('hilosTableStaleColumns', () => {
  it('picks the columns whose declared source froze, in declaration order', () => {
    const stale = hilosTableStaleColumns(
      columns,
      new Set(['volumes', 'connections']),
    )

    expect(stale.map((column) => column.key)).toEqual([
      'presence',
      'onlineSessionCount',
      'size',
    ])
  })

  it('never picks a column that declared no source', () => {
    const stale = hilosTableStaleColumns(columns, new Set(['name']))

    expect(stale).toEqual([])
  })
})

describe('hilosTableStaleLabel', () => {
  it('says nothing while the window is current', () => {
    expect(hilosTableStaleLabel([], false)).toBeUndefined()
  })

  it('names one frozen column in the singular', () => {
    const label = hilosTableStaleLabel(
      hilosTableStaleColumns(columns, new Set(['volumes'])),
      true,
    )

    expect(label).toBe(
      'Size is not updating: the link to its source was lost. The other columns are live.',
    )
  })

  it('joins two frozen columns with "and"', () => {
    const label = hilosTableStaleLabel(
      hilosTableStaleColumns(columns, new Set(['connections'])),
      true,
    )

    expect(label).toBe(
      'Presence and Sessions are not updating: the link to their source was lost. The other columns are live.',
    )
  })

  it('lists three frozen columns with commas and a final "and"', () => {
    const label = hilosTableStaleLabel(
      hilosTableStaleColumns(columns, new Set(['connections', 'volumes'])),
      true,
    )

    expect(label).toBe(
      'Presence, Sessions and Size are not updating: the link to their source was lost. The other columns are live.',
    )
  })

  it('falls back to the generic wording when no column named the frozen source', () => {
    // A table whose columns declared no source still has to say something: yesterday's
    // number standing silently next to today's is what this mark is written against.
    expect(hilosTableStaleLabel([], true)).toBe(TABLE_STALENESS_COPY.barUnnamed)
  })
})
