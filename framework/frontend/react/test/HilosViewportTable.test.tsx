import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type { HilosTableColumn, TableViewportDescriptor } from '@hilos/core'

import { HilosViewportTable } from '../src/HilosViewportTable.js'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

function makeController(): {
  controller: TableViewportController<Row>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots.name) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    pageSize: 10,
  })

  return { controller, sent }
}

function renderTable(controller: TableViewportController<Row>) {
  return render(
    <HilosViewportTable
      controller={controller}
      columns={COLUMNS}
      row={(r) => <td className="cell">{r.name}</td>}
    />,
  )
}

describe('HilosViewportTable', () => {
  afterEach(cleanup)

  it('renders a row per window row through the row render prop', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
    )
    const { container } = renderTable(controller)

    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]'),
    ).toHaveLength(2)
    expect(container.textContent).toContain('Alice')
  })

  it('sends a viewport when a sortable header is clicked', () => {
    const { controller, sent } = makeController()
    const { container } = renderTable(controller)

    fireEvent.click(
      container.querySelector('[data-id="hilos-table-sort-name"]') as Element,
    )

    expect(sent.at(-1)).toMatchObject({
      sort: { field: 'name', direction: 'asc' },
    })
  })

  it('shows the apply button with the pending count and applies in place', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-pending"]')?.textContent,
    ).toBe('1')
    fireEvent.click(
      container.querySelector('[data-id="hilos-table-apply"]') as Element,
    )

    expect(container.textContent).toContain('new')
    expect(container.querySelector('[data-id="hilos-table-apply"]')).toBeNull()
  })

  it('renders a placeholder for an applied removal', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'Alice' } }], 1)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-placeholder"]'),
    ).not.toBeNull()
    expect(container.textContent).not.toContain('Alice')
  })

  it('does not render a list-changed banner on a set change (no layout shift)', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'Alice' } }], 1)
    controller.ingestCount(5)
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-list-changed"]'),
    ).toBeNull()
  })
})
