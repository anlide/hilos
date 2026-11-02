// The Status Components tab in React: the real page, the real core table, the
// real shared head and HilosViewportTable with its search, filter and Apply
// strip; only the wire is fake. Scenarios mirror
// framework/frontend/vue/src/admin/status/HilosStatusComponentsPage.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, type TableRow } from '@hilos/core/base'

import { HilosStatusComponentsPage } from '../src/admin/status/HilosStatusComponentsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { statusHarness } from './statusFixtures.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
})

const states = [
  'operational',
  'checking',
  'partial_outage',
  'major_outage',
  'maintenance',
  'unknown',
] as const
const rows = states.map((state, index) => ({
  rowKey: `component-${index}`,
  slots: {
    component: {
      componentKey: `component-${index}`,
      name: `Component ${index}`,
      description: `Description ${index}`,
      agentType: `agent${index}`,
      state,
    },
  },
}))

function find(
  id: string,
  root: ParentNode = document.body,
): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string, root: ParentNode = document.body): HTMLElement {
  const element = find(id, root)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function table(): HTMLTableElement {
  const element = document.querySelector('table')
  if (element === null) throw new Error('Missing table')
  return element
}

/** The state badge of one component, as the desktop table draws it. */
function badge(componentKey: string): HTMLElement {
  return get(`hilos-status-component-state-${componentKey}`, table())
}

/**
 * Choose one option of the standard state filter.
 *
 * @param index The option's position in the filter's list.
 */
function chooseState(index: number): void {
  fireEvent.click(get('hilos-dropdown-toggle', get('hilos-table-filter-state')))
  fireEvent.click(get(`hilos-dropdown-option-${index}`))
}

function page(strict = false) {
  const h = statusHarness(HilosPages.STATUS_COMPONENTS)
  cleanups.push(h.dispose)
  const view = h.mount(
    <HilosStatusComponentsPage context={h.context} />,
    strict,
  )
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  const answer = (nextRows: readonly TableRow[] = rows, filter = {}) =>
    act(() => {
      h.emit('projectSignal', {
        type: 'page_response',
        data: {
          page: 'hilos_status_components',
          payload: {
            windows: {
              hilosStatusComponents: {
                rows: nextRows,
                totalCount: nextRows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                limit: 25,
                sort: null,
                filter,
              },
            },
          },
        },
      })
    })
  const delta = (data: Record<string, unknown>) =>
    act(() => {
      h.emit('tableViewportDelta', {
        data: {
          page: 'hilos_status_components',
          tableKey: 'hilosStatusComponents',
          ...data,
        },
      })
    })
  return { ...h, view, unmount, answer, delta }
}

describe('HilosStatusComponentsPage with the real viewport renderer', () => {
  it('names its tab row for the shared head and draws the named table without a mount fetch', () => {
    const { view, answer, sent, viewports, registered } = page()
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
    expect(registered.size).toBe(1)
    answer()
    expect(
      view.getAllByRole('navigation', { name: 'Status pages' }),
    ).toHaveLength(1)
    expect(get('hilos-status-components-table')).not.toBeNull()
    expect(view.getByRole('table', { name: 'Status' })).toBe(table())
    expect(
      [...table().querySelectorAll('tbody tr')].map((row) => row.textContent),
    ).toEqual(states.map((_, i) => expect.stringContaining(`Component ${i}`)))
    for (const label of [
      'Operational',
      'Checking',
      'Partial outage',
      'Major outage',
      'Maintenance',
      'Unknown',
    ])
      expect(table().textContent).toContain(label)
    expect(get('hilos-status-component-component-0', table()).textContent).toBe(
      'Component 0Description 0',
    )
    expect(table().querySelector('code')?.textContent).toBe('agent0')
    expect(table().querySelector('[aria-sort]')).toBeNull()
    expect(find('hilos-table-main-action')).toBeNull()
    expect(find('hilos-table-live-slot')).not.toBeNull()
    expect(sent).toEqual([])
  })

  it('leaves out the description line a component does not declare', () => {
    const { answer } = page()
    answer([
      {
        rowKey: 'component-0',
        slots: {
          component: { ...rows[0]!.slots.component, description: null },
        },
      },
    ])
    expect(get('hilos-status-component-component-0', table()).textContent).toBe(
      'Component 0',
    )
  })

  it('sends search and state filters through the standard controls, preserving server authority and empty-search copy', () => {
    const { answer, viewports } = page()
    answer()
    const search = get('hilos-table-search') as HTMLInputElement
    expect(search.getAttribute('aria-label')).toBe(
      'Search by name, description or agent…',
    )
    fireEvent.change(search, { target: { value: 'agent0' } })
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({ filter: { search: 'agent0' }, limit: 25 }),
    ])
    // The browser has requested a search; only a server window chooses its rows.
    answer([], { search: 'agent0' })
    expect(document.body.textContent).toContain('Nothing found')
    expect(document.body.textContent).not.toContain('All systems operational')
    fireEvent.click(
      get('hilos-dropdown-toggle', get('hilos-table-filter-state')),
    )
    expect(get('hilos-dropdown-menu').textContent).toContain('Unknown')
    fireEvent.click(get('hilos-dropdown-option-5'))
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({
        filter: { search: 'agent0', state: 'unknown' },
        sort: null,
        limit: 25,
      }),
    ])
  })

  it('updates a visible value immediately and keeps membership drift behind the real Apply strip', () => {
    const { answer, delta, viewports } = page()
    answer()
    delta({
      kind: 'row_updated',
      rowKey: 'component-0',
      row: {
        rowKey: 'component-0',
        slots: {
          component: { ...rows[0]!.slots.component, state: 'maintenance' },
        },
      },
    })
    expect(badge('component-0').textContent).toBe('Maintenance')
    delta({ kind: 'row_removed', rowKey: 'component-1', reason: 'left_set' })
    expect(table().textContent).toContain('Component 1')
    const apply = get('hilos-table-apply') as HTMLButtonElement
    expect(apply.disabled).toBe(false)
    fireEvent.click(apply)
    expect(find('hilos-status-component-component-1', table())).toBeNull()
    expect(find('hilos-table-apply')).toBeNull()
    expect(viewports).toEqual([])
  })

  it('renders metadata-only staleness as Unknown under a state filter without consuming Apply', () => {
    const { answer, delta, viewports } = page()
    const slot = Object.freeze({ ...rows[0]!.slots.component })
    const raw = Object.freeze({
      rowKey: 'component-0',
      slots: Object.freeze({ component: slot }),
    })
    const nextRows = [
      raw,
      {
        rowKey: 'component-1',
        slots: {
          component: { ...rows[1]!.slots.component, state: 'operational' },
        },
      },
    ]
    answer(nextRows)
    chooseState(0)
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({ filter: { state: 'operational' } }),
    ])
    answer(nextRows, { state: 'operational' })
    expect(badge('component-0').textContent).toBe('Operational')
    const requests = [...viewports]
    delta({ kind: 'row_removed', rowKey: 'component-0', reason: 'left_set' })
    for (const [staleSources, label] of [
      [['component'], 'Unknown'],
      [[], 'Operational'],
      [['unrelated'], 'Operational'],
      [['component'], 'Unknown'],
    ] as const) {
      delta({ kind: 'row_stale', rowKey: 'component-0', staleSources })
      expect(badge('component-0').textContent).toBe(label)
      expect(
        [...table().querySelectorAll('tbody tr')].map((row) =>
          row
            .querySelector('[data-id^="hilos-status-component-state-"]')
            ?.getAttribute('data-id'),
        ),
      ).toEqual([
        'hilos-status-component-state-component-0',
        'hilos-status-component-state-component-1',
      ])
      expect(get('hilos-table-pending').textContent).toBe('1')
      expect(
        get(
          'hilos-table-pending-remove-component-0',
          table(),
        ).textContent?.trim(),
      ).toBe('Will leave')
      expect((get('hilos-table-apply') as HTMLButtonElement).disabled).toBe(
        false,
      )
      expect(viewports).toEqual(requests)
      expect(raw.slots.component).toBe(slot)
      expect(slot.state).toBe('operational')
    }
    fireEvent.click(get('hilos-table-apply'))
    expect(find('hilos-status-component-component-0', table())).toBeNull()
    expect(find('hilos-table-apply')).toBeNull()
  })

  it.each([
    ['operational', 'Operational'],
    ['partial_outage', 'Partial outage'],
  ] as const)(
    'renders a stale first window as Unknown and restores authoritative %s on freshness',
    (state, label) => {
      const { answer, delta } = page()
      const slot = Object.freeze({ ...rows[0]!.slots.component, state })
      answer([
        {
          rowKey: 'component-0',
          slots: Object.freeze({ component: slot }),
          staleSources: ['component'],
        },
      ])
      expect(badge('component-0').textContent).toBe('Unknown')
      delta({ kind: 'row_stale', rowKey: 'component-0', staleSources: [] })
      expect(badge('component-0').textContent).toBe(label)
      expect(slot.state).toBe(state)
    },
  )

  it('shows the same read-only table to a viewer and sends nothing', () => {
    const h = statusHarness(HilosPages.STATUS_COMPONENTS)
    cleanups.push(h.dispose)
    h.viewer()
    const view = h.mount(<HilosStatusComponentsPage context={h.context} />)
    cleanups.push(view.unmount)
    act(() => {
      h.emit('projectSignal', {
        type: 'page_response',
        data: {
          page: 'hilos_status_components',
          payload: {
            windows: {
              hilosStatusComponents: {
                rows,
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                limit: 25,
                sort: null,
                filter: {},
              },
            },
          },
        },
      })
    })
    expect(table().querySelectorAll('tbody tr')).toHaveLength(rows.length)
    expect(badge('component-3').textContent).toBe('Major outage')
    expect(find('hilos-table-main-action')).toBeNull()
    expect(table().querySelector('input[type="checkbox"]')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('releases the table registration and listeners on unmount', () => {
    const { unmount, registered } = page()
    unmount()
    expect(registered.size).toBe(0)
  })
})

describe('HilosStatusComponentsPage lifecycle', () => {
  it('keeps one live table through the StrictMode replay and releases it on unmount', () => {
    const { answer, delta, unmount, registered, viewports } = page(true)
    expect(registered.size).toBe(1)
    answer()
    expect(table().querySelectorAll('tbody tr')).toHaveLength(rows.length)
    delta({
      kind: 'row_updated',
      rowKey: 'component-0',
      row: {
        rowKey: 'component-0',
        slots: {
          component: { ...rows[0]!.slots.component, state: 'major_outage' },
        },
      },
    })
    expect(badge('component-0').textContent).toBe('Major outage')
    fireEvent.change(get('hilos-table-search'), { target: { value: 'agent' } })
    expect(viewports).toHaveLength(1)
    unmount()
    expect(registered.size).toBe(0)
    expect(find('hilos-status-components-table')).toBeNull()
  })

  it('lets a late frame after unmount reach nothing, and a remount binds a fresh table', () => {
    const h = page()
    h.answer()
    h.unmount()
    h.answer([])
    h.delta({ kind: 'row_removed', rowKey: 'component-0', reason: 'left_set' })
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-components-table')).toBeNull()

    const again = h.mount(
      <HilosStatusComponentsPage context={h.context} />,
      true,
    )
    cleanups.push(again.unmount)
    expect(h.registered.size).toBe(1)
    h.answer()
    expect(table().querySelectorAll('tbody tr')).toHaveLength(rows.length)
    expect(find('hilos-table-apply')).toBeNull()
  })

  it('replaces the table when the context changes and leaves the old one unbound', () => {
    const h = page()
    h.answer()
    const next = { ...h.context }
    act(() => {
      h.view.rerender(
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusComponentsPage context={next} />
        </HilosRouterContext.Provider>,
      )
    })
    expect(h.registered.size).toBe(1)
    h.answer(rows.slice(0, 2))
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
  })
})
