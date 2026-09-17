import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import { HilosTableBar } from '../src/HilosTableBar.js'

// The React port of vue/src/HilosTableBar.test.ts, under the same case names,
// for the layer the React bar draws: title, search, filters, badge, main action,
// and the filters modal. The modal portals to <body>, so every query reads the
// document rather than the render.

const COLUMNS = [{ key: 'name', label: 'Name' }]

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  return { controller, sent }
}

const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function renderBar(controller: TableViewportController<unknown>) {
  return render(<HilosTableBar controller={controller} titleId="table-title" />)
}

function type(id: string, value: string): void {
  fireEvent.change(byId(id) as HTMLElement, { target: { value } })
}

describe('HilosTableBar', () => {
  it('shows the declared title and subtitle, the title carrying the given id', () => {
    const { controller } = makeController({
      title: 'Backups',
      subtitle: 'Every copy this installation keeps',
      columns: COLUMNS,
    })
    renderBar(controller)

    const title = byId('hilos-table-title')
    expect(title?.textContent).toBe('Backups')
    expect(title?.id).toBe('table-title')
    expect(byId('hilos-table-subtitle')?.textContent).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-subtitle')).toBeNull()
  })

  it('draws no heading and no subtitle when the declaration carries no title', () => {
    // The page heading names such a table; an empty h2 would be a heading with
    // nothing to say, and a subtitle would hang under a heading that is not there.
    const { controller } = makeController({
      subtitle: 'Every copy this installation keeps',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-title')).toBeNull()
    expect(document.querySelector('h2')).toBeNull()
    expect(byId('hilos-table-subtitle')).toBeNull()
    expect(byId('hilos-table-search')).not.toBeNull()
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-search')).toBeNull()
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    renderBar(controller)
    const field = byId('hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search backups…')
    expect(field?.getAttribute('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-search')?.getAttribute('placeholder')).toBe(
      'Search…',
    )
  })

  it('sends a window on every keystroke of the search field', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    type('hilos-table-search', 'nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)
    expect(byId('hilos-table-search-clear')).toBeNull()

    type('hilos-table-search', 'nig')
    fireEvent.click(byId('hilos-table-search-clear') as HTMLElement)

    expect(sent.at(-1)?.filter).not.toHaveProperty('search')
  })

  it('presses the declared main action exactly once per click', () => {
    let pressed = 0
    const { controller } = makeController({
      title: 'Backups',
      mainAction: {
        label: 'Create backup',
        press: () => {
          pressed += 1
        },
      },
      columns: COLUMNS,
    })
    renderBar(controller)

    const button = byId('hilos-table-main-action') as HTMLElement
    expect(button.textContent).toBe('Create backup')
    fireEvent.click(button)

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)

    expect(byId('hilos-table-filter-state')).not.toBeNull()
    expect(byId('hilos-table-filter-unread')).not.toBeNull()
  })

  it('counts the filters holding a value in the badge, and never the search', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('1 filter')

    type('hilos-table-search', 'nig')
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('1 filter')

    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('2 filters')
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)

    expect(byId('hilos-table-filter-badge')).toBeNull()
  })

  it('resets to the filters the table opened with and clears the search with them', () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    type('hilos-table-search', 'nig')
    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)

    fireEvent.click(byId('hilos-table-filter-reset') as HTMLElement)

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect((byId('hilos-table-search') as HTMLInputElement).value).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)
    expect(byId('modal')).toBeNull()

    fireEvent.click(byId('hilos-table-filters-open') as HTMLElement)
    expect(byId('modal')).not.toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-table-filter-state-modal"]'),
    ).toHaveLength(1)

    act(() => {
      byId('hilos-table-filters-done')?.click()
    })

    expect(byId('modal')).toBeNull()
  })

  it('keeps the button that opens the filters free of their number', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)

    const open = byId('hilos-table-filters-open') as HTMLElement
    expect(open.textContent).toBe('Filters')
    expect(open.querySelector('.badge')).toBeNull()
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-filters-open')).toBeNull()
    expect(byId('modal')).toBeNull()
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-main-action')).toBeNull()
  })
})
