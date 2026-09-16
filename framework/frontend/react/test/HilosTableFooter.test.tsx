import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type { TableRow, TableViewportDescriptor } from '@hilos/core'

import { HilosTableFooter } from '../src/HilosTableFooter.js'

// The React port of vue/src/HilosTableFooter.test.ts, under the same case names,
// for the layer the React footer draws: the range, the count, and the two steps.

// The footer says nothing about the declaration, but a table only draws one
// when it has a frame — so the tests give it the smallest one there is.
const FRAME = { title: 'Backups', columns: [{ key: 'name', label: 'Name' }] }

afterEach(() => cleanup())

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function makeController(): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: FRAME,
  })

  return { controller, sent }
}

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

// Paging asks the server for the rows after the window's last one, so a window
// that carries no boundary cannot be paged from at all: these tests hand over
// the anchors a real window arrives with.
function ingestPage(
  controller: TableViewportController<unknown>,
  totalCount: number,
): void {
  const rows = window(20)
  controller.ingestWindow(
    rows,
    totalCount,
    true,
    { rowKey: rows[0]?.rowKey },
    { rowKey: rows[rows.length - 1]?.rowKey },
    20,
  )
}

function renderFooter(controller: TableViewportController<unknown>) {
  return render(<HilosTableFooter controller={controller} />)
}

describe('HilosTableFooter', () => {
  it('prints the range on screen and an exact total', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 128, true, null, null, 20)
    renderFooter(controller)

    expect(byId('hilos-table-count')?.textContent).toBe('1 – 20 of 128')
  })

  it('marks a total that stopped at its ceiling with a trailing plus', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 500, false, null, null, 20)
    renderFooter(controller)

    expect(byId('hilos-table-count')?.textContent).toBe('1 – 20 of 500+')
  })

  it('counts the range from the page the window sits on', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    renderFooter(controller)

    fireEvent.click(byId('hilos-table-next') as HTMLElement)
    act(() => ingestPage(controller, 128))

    expect(byId('hilos-table-count')?.textContent).toBe('21 – 40 of 128')
  })

  it('draws nothing at all on an empty window', () => {
    const { controller } = makeController()
    controller.ingestWindow([], 0, true, null, null, 20)
    renderFooter(controller)

    expect(byId('hilos-table-count')).toBeNull()
    expect(byId('hilos-table-prev')).toBeNull()
  })

  it('disables the step back on the first page and the step on at the last', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 20, true, null, null, 20)
    renderFooter(controller)

    expect((byId('hilos-table-prev') as HTMLButtonElement).disabled).toBe(true)
    expect((byId('hilos-table-next') as HTMLButtonElement).disabled).toBe(true)
  })

  it('steps the window on and back through the controller', () => {
    const { controller, sent } = makeController()
    ingestPage(controller, 128)
    renderFooter(controller)

    fireEvent.click(byId('hilos-table-next') as HTMLElement)
    expect(sent).toHaveLength(1)

    act(() => ingestPage(controller, 128))
    fireEvent.click(byId('hilos-table-prev') as HTMLElement)

    expect(sent).toHaveLength(2)
  })
})
