import { By } from '@angular/platform-browser'
import { HilosPages, type TableRow } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosStatusComponentsPage } from '../src/admin/status/HilosStatusComponentsPage.js'
import { el, mountStatus, statusHarness } from './helpers/hilosStatus.js'

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

function page() {
  const h = mountStatus(HilosStatusComponentsPage, HilosPages.STATUS_COMPONENTS)
  return {
    ...h,
    window(nextRows: readonly TableRow[] = rows, filter = {}) {
      h.act(() =>
        h.emit('projectSignal', {
          type: 'page_response',
          data: {
            page: HilosPages.STATUS_COMPONENTS,
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
        }),
      )
    },
    delta(data: Record<string, unknown>) {
      h.act(() =>
        h.emit('tableViewportDelta', {
          data: {
            page: HilosPages.STATUS_COMPONENTS,
            tableKey: 'hilosStatusComponents',
            ...data,
          },
        }),
      )
    },
  }
}
const table = () => document.querySelector('table')!
const badge = (index: number) =>
  table().querySelector(
    `[data-id="hilos-status-component-state-component-${index}"]`,
  )!

describe('HilosStatusComponentsPage over the real viewport', () => {
  it('hands the shared head its section identity and renders all authoritative states with no write or sort controls', () => {
    const h = page()
    const head = h.fixture.debugElement.query(By.directive(HilosAdminPage))
      .componentInstance as HilosAdminPage
    expect([head.page(), head.headKind(), head.tabsLabel()]).toEqual([
      HilosPages.STATUS_COMPONENTS,
      'section',
      'Status pages',
    ])
    expect(h.registered.size).toBe(1)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    h.window()
    expect(
      [...table().querySelectorAll('tbody tr')].map((row) => row.textContent),
    ).toEqual(
      states.map((_, index) => expect.stringContaining(`Component ${index}`)),
    )
    for (const label of [
      'Operational',
      'Checking',
      'Partial outage',
      'Major outage',
      'Maintenance',
      'Unknown',
    ])
      expect(table().textContent).toContain(label)
    expect(table().textContent).toContain('Description 0')
    expect(table().textContent).toContain('agent0')
    expect(table().querySelector('[aria-sort]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-table-main-action"]'),
    ).toBeNull()
  })

  it('sends search and state filters through the table frame, leaving row selection to the server', () => {
    const h = page()
    h.window()
    const search = el<HTMLInputElement>('hilos-table-search')
    expect(search.getAttribute('aria-label')).toBe(
      'Search by name, description or agent…',
    )
    h.act(() => {
      search.value = 'agent0'
      search.dispatchEvent(new Event('input', { bubbles: true }))
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_COMPONENTS,
      'hilosStatusComponents',
      expect.objectContaining({ filter: { search: 'agent0' }, limit: 25 }),
    ])
    expect(table().textContent).toContain('Component 1')
    h.window([], { search: 'agent0' })
    expect(h.fixture.nativeElement.textContent).toContain('Nothing found')
    h.act(() =>
      el('hilos-table-filter-state')
        .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
        .click(),
    )
    h.act(() => el('hilos-dropdown-option-5').click())
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_COMPONENTS,
      'hilosStatusComponents',
      expect.objectContaining({
        filter: { search: 'agent0', state: 'unknown' },
        sort: null,
        limit: 25,
      }),
    ])
  })

  it('refreshes shown values immediately while preserving membership changes for Apply', () => {
    const h = page()
    h.window()
    h.delta({
      kind: 'row_updated',
      rowKey: 'component-0',
      row: {
        ...rows[0],
        slots: {
          component: { ...rows[0]!.slots.component, state: 'maintenance' },
        },
      },
    })
    expect(badge(0).textContent?.trim()).toBe('Maintenance')
    h.delta({ kind: 'row_removed', rowKey: 'component-1', reason: 'left_set' })
    expect(table().textContent).toContain('Component 1')
    h.act(() => el('hilos-table-apply').click())
    expect(table().textContent).not.toContain('Component 1')
    expect(h.viewports).toEqual([])
  })

  it('renders stale source metadata as Unknown and restores the slot without consuming a pending removal', () => {
    const h = page()
    const slot = Object.freeze({ ...rows[0]!.slots.component })
    const raw = Object.freeze({
      rowKey: 'component-0',
      slots: Object.freeze({ component: slot }),
      staleSources: ['component'],
    })
    h.window([raw, rows[1]!])
    expect(badge(0).textContent?.trim()).toBe('Unknown')
    h.delta({ kind: 'row_removed', rowKey: 'component-0', reason: 'left_set' })
    for (const [staleSources, label] of [
      [[], 'Operational'],
      [['component'], 'Unknown'],
      [['unrelated'], 'Operational'],
    ] as const) {
      h.delta({ kind: 'row_stale', rowKey: 'component-0', staleSources })
      expect(badge(0).textContent?.trim()).toBe(label)
      expect(table().textContent).toContain('Will leave')
      expect(el<HTMLButtonElement>('hilos-table-apply').disabled).toBe(false)
      expect(slot.state).toBe('operational')
    }
    h.act(() => el('hilos-table-apply').click())
    expect(
      table().querySelector('[data-id="hilos-status-component-component-0"]'),
    ).toBeNull()
    expect(h.viewports).toEqual([])
  })

  it('releases the old context registration on replacement and the final viewport on destroy', () => {
    const h = page()
    h.window()
    const second = statusHarness(HilosPages.STATUS_COMPONENTS)
    try {
      h.fixture.componentRef.setInput('context', second.context)
      h.detect()
      expect(h.registered.size).toBe(0)
      expect(second.registered.size).toBe(1)
      h.fixture.destroy()
      expect(second.registered.size).toBe(0)
    } finally {
      second.dispose()
    }
  })
})
