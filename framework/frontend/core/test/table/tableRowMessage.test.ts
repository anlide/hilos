import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from '../../src/table/hilosTableColumn.js'
import { hilosTableRowMessageCells } from '../../src/table/tableRowMessage.js'

describe('hilosTableRowMessageCells', () => {
  const wordsColumns: readonly HilosTableColumn[] = [
    { key: 'operation', label: 'Operation' },
    { key: 'title', label: 'Title' },
    { key: 'phrase', label: 'Phrase' },
    { key: 'bar', label: 'Bar' },
  ]

  it('keeps ordinary row cells without message, spanning 1 with null text', () => {
    expect(hilosTableRowMessageCells(wordsColumns, null)).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 1, text: null },
      { key: 'phrase', span: 1, text: null },
      { key: 'bar', span: 1, text: null },
    ])
    expect(hilosTableRowMessageCells(wordsColumns, undefined)).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 1, text: null },
      { key: 'phrase', span: 1, text: null },
      { key: 'bar', span: 1, text: null },
    ])
  })

  it('merges visible tail columns from fromColumn into a single message cell', () => {
    const cells = hilosTableRowMessageCells(wordsColumns, {
      fromColumn: 'title',
      text: 'No own entry — uses default',
    })

    expect(cells).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 3, text: 'No own entry — uses default' },
    ])
  })

  it('merges the entire row when fromColumn is the first visible column', () => {
    const cells = hilosTableRowMessageCells(wordsColumns, {
      fromColumn: 'operation',
      text: 'Entire row message',
    })

    expect(cells).toEqual([
      { key: 'operation', span: 4, text: 'Entire row message' },
    ])
  })

  it('spans 1 when fromColumn is the last visible column', () => {
    const cells = hilosTableRowMessageCells(wordsColumns, {
      fromColumn: 'bar',
      text: 'Last column message',
    })

    expect(cells).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 1, text: null },
      { key: 'phrase', span: 1, text: null },
      { key: 'bar', span: 1, text: 'Last column message' },
    ])
  })

  it('calculates boundaries over visible columns, ignoring detail columns before fromColumn', () => {
    const columnsWithDetail: readonly HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: 'details', label: 'Expanded Notes', detail: true },
      { key: 'title', label: 'Title' },
      { key: 'phrase', label: 'Phrase' },
      { key: 'bar', label: 'Bar' },
    ]

    expect(hilosTableRowMessageCells(columnsWithDetail, null)).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 1, text: null },
      { key: 'phrase', span: 1, text: null },
      { key: 'bar', span: 1, text: null },
    ])

    const cells = hilosTableRowMessageCells(columnsWithDetail, {
      fromColumn: 'title',
      text: 'No own entry — uses default',
    })

    expect(cells).toEqual([
      { key: 'operation', span: 1, text: null },
      { key: 'title', span: 3, text: 'No own entry — uses default' },
    ])
  })

  it('allows actions column before fromColumn without swallowing controls', () => {
    const columnsWithActionsBefore: readonly HilosTableColumn[] = [
      { key: HILOS_TABLE_ACTIONS_KEY, label: '' },
      { key: 'title', label: 'Title' },
      { key: 'phrase', label: 'Phrase' },
    ]

    const cells = hilosTableRowMessageCells(columnsWithActionsBefore, {
      fromColumn: 'title',
      text: 'Merged tail',
    })

    expect(cells).toEqual([
      { key: HILOS_TABLE_ACTIONS_KEY, span: 1, text: null },
      { key: 'title', span: 2, text: 'Merged tail' },
    ])
  })

  it('refuses an unknown fromColumn with TypeError', () => {
    expect(() =>
      hilosTableRowMessageCells(wordsColumns, {
        fromColumn: 'unknown',
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })

  it('refuses when fromColumn points to a detail column with TypeError', () => {
    const columns: readonly HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: 'note', label: 'Note', detail: true },
      { key: 'title', label: 'Title' },
    ]

    expect(() =>
      hilosTableRowMessageCells(columns, {
        fromColumn: 'note',
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })

  it('refuses when the merged tail contains a detail column with TypeError', () => {
    const columns: readonly HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: 'title', label: 'Title' },
      { key: 'note', label: 'Note', detail: true },
      { key: 'bar', label: 'Bar' },
    ]

    expect(() =>
      hilosTableRowMessageCells(columns, {
        fromColumn: 'title',
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })

  it('refuses when the merged tail contains an actions column with TypeError', () => {
    const columns: readonly HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: 'title', label: 'Title' },
      { key: HILOS_TABLE_ACTIONS_KEY, label: '' },
    ]

    expect(() =>
      hilosTableRowMessageCells(columns, {
        fromColumn: 'title',
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })

  it('refuses when fromColumn is the actions column with TypeError', () => {
    const columns: readonly HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: HILOS_TABLE_ACTIONS_KEY, label: '' },
    ]

    expect(() =>
      hilosTableRowMessageCells(columns, {
        fromColumn: HILOS_TABLE_ACTIONS_KEY,
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })

  it('returns empty array when columns list is empty and no message is provided', () => {
    expect(hilosTableRowMessageCells([], null)).toEqual([])
  })

  it('refuses with TypeError when columns list is empty and a message is provided', () => {
    expect(() =>
      hilosTableRowMessageCells([], {
        fromColumn: 'title',
        text: 'Invalid',
      }),
    ).toThrow(TypeError)
  })
})
