import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import { HilosTableEmptyState } from '../src/HilosTableEmptyState.js'

// The React port of vue/src/HilosTableEmptyState.test.ts, under the same case
// names, for the three worded states of the body of a table.

afterEach(() => cleanup())

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function makeController(frame?: HilosTableFrame): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter: { channel: 'mail' },
    frame,
  })
  controller.ingestWindow([], 0, true, null, null, 10)

  return { controller, sent }
}

const FILTERED_FRAME: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  columns: [{ key: 'name', label: 'Name' }],
  filters: [
    {
      kind: 'select',
      key: 'status',
      label: 'Status',
      options: () => [{ value: 'failed', label: 'Failed' }],
    },
    { kind: 'date_range', fromKey: 'from', toKey: 'to', label: 'Period' },
    { kind: 'toggle', key: 'mine', label: 'Mine only', on: true },
  ],
}

describe('HilosTableEmptyState', () => {
  it('says the page own words and offers its main action', () => {
    const press = vi.fn()
    const { controller } = makeController({
      title: 'Backups',
      columns: [{ key: 'name', label: 'Name' }],
      empty: {
        title: 'Nothing here yet',
        hint: 'Your first backup will show up here',
      },
      mainAction: { label: 'Create backup', press },
    })
    render(<HilosTableEmptyState controller={controller} kind="empty" />)

    expect(byId('hilos-table-empty')?.getAttribute('role')).toBe('status')
    expect(byId('hilos-table-empty-title')?.textContent).toBe(
      'Nothing here yet',
    )
    expect(byId('hilos-table-empty-hint')?.textContent).toBe(
      'Your first backup will show up here',
    )

    const action = byId('hilos-table-empty-action')
    expect(action?.textContent).toBe('Create backup')
    fireEvent.click(action as HTMLElement)
    expect(press).toHaveBeenCalledTimes(1)
  })

  it('falls back to what the page passed in the slot when it declared no empty state', () => {
    const { controller } = makeController()
    render(
      <HilosTableEmptyState controller={controller} kind="empty">
        <span className="none-yet">No rows.</span>
      </HilosTableEmptyState>,
    )

    expect(
      document.querySelector('[data-id="hilos-table-empty"] .none-yet')
        ?.textContent,
    ).toBe('No rows.')
    expect(byId('hilos-table-empty-title')).toBeNull()
    expect(byId('hilos-table-empty-action')).toBeNull()
  })

  it('names the query and every active filter it found nothing for', () => {
    const { controller } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    controller.setFilters({ from: '2026-08-01', to: '2026-08-31' })
    controller.setFilter('mine', true)
    render(
      <HilosTableEmptyState controller={controller} kind="empty_filtered" />,
    )

    const state = byId('hilos-table-no-matches')
    expect(state?.getAttribute('role')).toBe('status')
    expect(state?.textContent).toContain('Nothing found')
    expect(byId('hilos-table-no-matches-terms')?.textContent).toBe(
      'No rows match “night” · Status: Failed · Period: 2026-08-01 – 2026-08-31 · Mine only',
    )

    // A half-open range leaves the missing bound out of the sentence.
    act(() => controller.setFilters({ from: '', to: '2026-08-31' }))
    expect(byId('hilos-table-no-matches-terms')?.textContent).toContain(
      'Period: until 2026-08-31',
    )
    act(() => controller.setFilters({ from: '2026-08-01', to: '' }))
    expect(byId('hilos-table-no-matches-terms')?.textContent).toContain(
      'Period: from 2026-08-01',
    )
  })

  it('offers the way back to the rows when the window is empty over a set that is not', () => {
    const { controller, sent } = makeController(FILTERED_FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      21,
      true,
      null,
      null,
      10,
      0,
    )
    controller.setPage(2)
    // The third page came back holding nothing while the set holds twenty-one rows: the
    // rows under that address moved while the page was open.
    controller.ingestWindow([], 21, true, null, null, 10, 20)
    render(<HilosTableEmptyState controller={controller} kind="empty_page" />)

    const state = byId('hilos-table-empty-page') as HTMLElement
    expect(state.getAttribute('role')).toBe('status')
    expect(state.textContent).toContain('Nothing on this page')
    expect(state.textContent).toContain(
      'These rows moved while the page was open.',
    )

    act(() => {
      fireEvent.click(byId('hilos-table-empty-page-back') as HTMLElement)
    })

    // The same thing Back in the footer does, and it asks for a place rather than a filter.
    expect(sent.at(-1)?.pageIndex).toBe(1)
  })

  it('resets the search and the filters back to the ones the table opened with', () => {
    const { controller, sent } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    render(
      <HilosTableEmptyState controller={controller} kind="empty_filtered" />,
    )

    act(() => {
      fireEvent.click(byId('hilos-table-no-matches-reset') as HTMLElement)
    })

    expect(sent.at(-1)?.filter).toEqual({ channel: 'mail' })
  })
})
