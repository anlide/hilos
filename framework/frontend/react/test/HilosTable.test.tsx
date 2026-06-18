import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { TableController, createSignal } from '@hilos/core'
import type { HilosTableColumn, TableRow } from '@hilos/core'

import { HilosTable } from '../src/HilosTable.js'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

function makeController(pageSize = 0): TableController<Row> {
  // Non-alphabetical arrival order so sorting is observable.
  const source = createSignal<readonly TableRow[]>([
    { rowKey: 'b', slots: { name: 'Bob' } },
    { rowKey: 'a', slots: { name: 'Alice' } },
    { rowKey: 'c', slots: { name: 'Carol' } },
  ])

  return new TableController<Row>({
    source,
    resolve: (raw) => ({ name: String(raw.slots.name) }),
    searchText: (row) => row.name,
    sortValue: (row) => row.name,
    pageSize,
  })
}

function rowCount(container: HTMLElement): number {
  return container.querySelectorAll('[data-id^="hilos-table-row-"]').length
}

function cellTexts(container: HTMLElement): (string | null)[] {
  return [...container.querySelectorAll('td.cell')].map(
    (cell) => cell.textContent,
  )
}

describe('HilosTable', () => {
  afterEach(cleanup)

  it('renders a row per controller row through the row render prop', () => {
    const { container } = render(
      <HilosTable
        controller={makeController()}
        columns={COLUMNS}
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
    expect(rowCount(container)).toBe(3)
    expect(container.textContent).toContain('Alice')
  })

  it('filters through the search box', () => {
    const { container } = render(
      <HilosTable
        controller={makeController()}
        columns={COLUMNS}
        searchable
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
    fireEvent.change(
      container.querySelector('[data-id="hilos-table-search"]') as Element,
      { target: { value: 'bob' } },
    )
    expect(rowCount(container)).toBe(1)
    expect(container.textContent).toContain('Bob')
  })

  it('sorts when a sortable header is clicked', () => {
    const { container } = render(
      <HilosTable
        controller={makeController()}
        columns={COLUMNS}
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
    const header = () =>
      container.querySelector('[data-id="hilos-table-sort-name"]') as Element

    fireEvent.click(header())
    expect(cellTexts(container)).toEqual(['Alice', 'Bob', 'Carol'])
    fireEvent.click(header())
    expect(cellTexts(container)).toEqual(['Carol', 'Bob', 'Alice'])
  })

  it('paginates', () => {
    const { container } = render(
      <HilosTable
        controller={makeController(2)}
        columns={COLUMNS}
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
    expect(rowCount(container)).toBe(2)
    expect(
      container.querySelector('[data-id="hilos-table-page"]')?.textContent,
    ).toContain('1 / 2')

    fireEvent.click(
      container.querySelector('[data-id="hilos-table-next"]') as Element,
    )
    expect(rowCount(container)).toBe(1)
  })
})
