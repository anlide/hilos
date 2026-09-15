import { describe, expect, it } from 'vitest'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from '../../src/table/hilosTableColumn.js'
import { hilosTableCard } from '../../src/table/tableCard.js'
import { type HilosTableFrame } from '../../src/table/tableFrame.js'

function makeController(frame?: HilosTableFrame) {
  return new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: () => undefined,
    frame,
  })
}

const actionsColumn: HilosTableColumn = {
  key: HILOS_TABLE_ACTIONS_KEY,
  label: '',
}

describe('hilosTableCard', () => {
  it('repeats the declared columns and their labels', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'kind', label: 'Kind' },
      { key: 'size', label: 'Size' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields.map((column) => column.key)).toEqual(['kind', 'size'])
    expect(card.fields.map((column) => column.label)).toEqual(['Kind', 'Size'])
  })

  it('sends the actions column to the foot of the card, never to a field', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      actionsColumn,
      { key: 'size', label: 'Size' },
    ])

    expect(card.actions).toBe(actionsColumn)
    expect(card.fields.map((column) => column.key)).toEqual(['size'])
  })

  it('assigns no badge unless a column asks for it', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'state', label: 'State' },
    ])

    expect(card.badge).toBeNull()
  })

  it('takes a badge column out of the fields and into the head', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'state', label: 'State', card: 'badge' },
      { key: 'size', label: 'Size' },
    ])

    expect(card.badge?.key).toBe('state')
    expect(card.fields.map((column) => column.key)).toEqual(['size'])
  })

  it('keeps a hidden column out of the card and leaves the rest in order', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'kind', label: 'Kind', card: 'hidden' },
      { key: 'size', label: 'Size' },
      { key: 'node', label: 'Node' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields.map((column) => column.key)).toEqual(['size', 'node'])
  })

  it('keeps a detail column out of the main set: the card expands to it instead', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'lastError', label: 'Error', detail: true },
      { key: 'size', label: 'Size' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields.map((column) => column.key)).toEqual(['size'])
  })

  it('never titles a card with a detail column, even as the first declared', () => {
    const card = hilosTableCard([
      { key: 'lastError', label: 'Error', detail: true },
      { key: 'created', label: 'Date' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields).toEqual([])
  })

  it('gives a taken place to the first claimant and makes the second a field', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date', card: 'title' },
      { key: 'kind', label: 'Kind', card: 'title' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields.map((column) => column.key)).toEqual(['kind'])
  })

  it('lets a marked column take the title over an unmarked one declared above it', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'name', label: 'Name', card: 'title' },
    ])

    expect(card.title?.key).toBe('name')
  })

  it('leaves the column the mark displaced a field in its declared place', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: 'name', label: 'Name', card: 'title' },
      { key: 'size', label: 'Size' },
    ])

    expect(card.fields.map((column) => column.key)).toEqual(['created', 'size'])
  })

  it('never lets a detail column claim the title with a mark', () => {
    const card = hilosTableCard([
      { key: 'lastError', label: 'Error', detail: true, card: 'title' },
      { key: 'created', label: 'Date' },
    ])

    expect(card.title?.key).toBe('created')
    expect(card.fields).toEqual([])
  })

  it('lets the actions key outrank a mark on the actions column', () => {
    const card = hilosTableCard([
      { key: HILOS_TABLE_ACTIONS_KEY, label: '', card: 'title' },
      { key: 'created', label: 'Date' },
    ])

    expect(card.actions?.key).toBe(HILOS_TABLE_ACTIONS_KEY)
    expect(card.title?.key).toBe('created')
    expect(card.fields).toEqual([])
  })

  it('still drops a hidden actions column from the card', () => {
    const card = hilosTableCard([
      { key: 'created', label: 'Date' },
      { key: HILOS_TABLE_ACTIONS_KEY, label: '', card: 'hidden' },
    ])

    expect(card.actions).toBeNull()
    expect(card.fields).toEqual([])
  })

  it('builds an empty card from no columns at all', () => {
    const card = hilosTableCard([])

    expect(card.title).toBeNull()
    expect(card.badge).toBeNull()
    expect(card.actions).toBeNull()
    expect(card.fields).toEqual([])
  })

  it('builds a card with actions alone from a lone actions column', () => {
    const card = hilosTableCard([actionsColumn])

    expect(card.title).toBeNull()
    expect(card.fields).toEqual([])
    expect(card.actions).toBe(actionsColumn)
  })
})

describe('TableViewportController frame card', () => {
  it('has no card when the page declared no frame', () => {
    expect(makeController().frame.card).toBeNull()
  })

  it('reads back the layout the declared columns derive', () => {
    const columns: readonly HilosTableColumn[] = [
      { key: 'createdAt', label: 'Date' },
      { key: 'state', label: 'State', card: 'badge' },
      { key: 'size', label: 'Size' },
      actionsColumn,
    ]
    const card = makeController({ title: 'Backups', columns }).frame.card

    expect(card).toEqual(hilosTableCard(columns))
    expect(card?.title?.key).toBe('createdAt')
    expect(card?.badge?.key).toBe('state')
    expect(card?.fields.map((column) => column.key)).toEqual(['size'])
    expect(card?.actions).toBe(actionsColumn)
  })
})
