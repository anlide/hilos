import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalAcceptancesPage } from '../src/admin/legal/HilosLegalAcceptancesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  hilosBrowserTimeZone,
  hilosTimeZone,
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosLegalAcceptancesExportNode,
  type HilosLegalContext,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { readerTimeZone } from './readerTimeZone.js'

/** The acceptance's person fields, as the server sent them to this viewer. */
interface PersonFields {
  name: unknown
  email: unknown
}

interface AcceptanceFixture extends PersonFields {
  rowKey: number
  acceptedAt: unknown
}

const ACCEPTED_AT = Date.parse('2026-10-24T03:30:00Z')

/** The live window is real; only its transport is replaced, and the rows arrive by `push`. */
function harness() {
  const listeners: Array<(frame: never) => void> = []
  const requests = { registrations: 0, viewports: 0, windows: 0 }
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_ACCEPTANCES)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        if (event === 'tableWindow') listeners.push(listener)
        return () => {}
      },
      registerTableWindow() {
        requests.registrations += 1
      },
      unregisterTableWindow() {},
      sendTableViewport() {
        requests.viewports += 1
      },
      sendTableRendered() {},
      sendTableFacets() {},
      sendTableRowFocus() {},
    },
    actions: {},
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_ACCEPTANCES,
      params: {},
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return {
    context,
    router,
    requests,
    push(acceptances: AcceptanceFixture[]) {
      requests.windows += 1
      for (const listener of listeners)
        listener({
          data: {
            page: HilosPages.LEGAL_ACCEPTANCES,
            tableKey: 'hilosLegalAcceptances',
            rows: acceptances.map(({ rowKey, name, email, acceptedAt }) => ({
              rowKey,
              slots: {
                acceptance: {
                  userId: rowKey,
                  name,
                  email,
                  document: 'terms',
                  revisionId: 'current',
                  declared: true,
                  acceptedAt,
                },
              },
            })),
            totalCount: acceptances.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
          },
        } as never)
    },
  }
}

function mountOver(person: PersonFields): HTMLElement {
  const h = harness()
  const view = render(
    <HilosRouterContext.Provider value={h.router}>
      <HilosLegalAcceptancesPage context={h.context} />
    </HilosRouterContext.Provider>,
  )
  act(() => h.push([{ rowKey: 42, ...person, acceptedAt: ACCEPTED_AT }]))
  return view.container.querySelector(
    '[data-id="legal-acceptance-row"]',
  ) as HTMLElement
}

afterEach(cleanup)

function formatIndependent(moment: number, timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    timeZone,
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(moment)
}

describe('HilosLegalAcceptancesPage (HIL-1260)', () => {
  it('draws the mark for a hidden name and a hidden email, and says nothing about email', () => {
    const record = mountOver({ name: HIDDEN_VALUE, email: HIDDEN_VALUE })

    expect(record.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(record.textContent).not.toContain('No verified email')
  })

  it('says "No verified email" only when there is none', () => {
    const record = mountOver({ name: 'Reader', email: null })

    expect(record.querySelector('strong')?.textContent).toBe('Reader')
    expect(record.textContent).toContain('No verified email')
    expect(record.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })

  it('draws accepted moments live in the reader zone without replacing the table window', () => {
    const reader = readerTimeZone()
    try {
      const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
      const readerZone =
        browserZone === 'Pacific/Honolulu' ? 'Asia/Tokyo' : 'Pacific/Honolulu'
      const h = harness()
      act(() => reader.choose('UTC'))
      const view = render(
        <HilosRouterContext.Provider value={h.router}>
          <HilosLegalAcceptancesPage context={h.context} />
        </HilosRouterContext.Provider>,
      )
      const table = view.container.querySelector('table')
      const fixtures: AcceptanceFixture[] = [
        {
          rowKey: 42,
          name: 'Reader',
          email: 'reader@example.com',
          acceptedAt: ACCEPTED_AT,
        },
        {
          rowKey: 43,
          name: 'Epoch',
          email: 'epoch@example.com',
          acceptedAt: 0,
        },
        {
          rowKey: 44,
          name: 'Missing',
          email: 'missing@example.com',
          acceptedAt: null,
        },
        {
          rowKey: 45,
          name: 'Invalid',
          email: 'invalid@example.com',
          acceptedAt: 8_640_000_000_000_001,
        },
      ]
      act(() => h.push(fixtures))

      const acceptanceTime = (rowKey: number): HTMLElement =>
        view.container.querySelector(
          `[data-id="hilos-table-row-${rowKey}"] [data-id="legal-acceptance-accepted-at"]`,
        ) as HTMLElement
      const firstRow = view.container.querySelector(
        '[data-id="hilos-table-row-42"]',
      )
      expect(acceptanceTime(42).textContent).toBe(
        formatIndependent(ACCEPTED_AT, 'UTC'),
      )
      expect(acceptanceTime(42).textContent).not.toBe('')
      expect(acceptanceTime(43).textContent).toBe(formatIndependent(0, 'UTC'))
      expect(acceptanceTime(43).textContent).not.toBe('')
      expect(acceptanceTime(44).textContent).toBe('—')
      expect(acceptanceTime(45).textContent).toBe('—')
      expect(readerZone).not.toBe(browserZone)

      const beforeZoneChange = { ...h.requests }
      act(() => reader.choose(readerZone))

      expect(hilosTimeZone.get()).toBe(readerZone)
      expect(h.requests).toEqual(beforeZoneChange)
      expect(view.container.querySelector('table')).toBe(table)
      expect(
        view.container.querySelector('[data-id="hilos-table-row-42"]'),
      ).toBe(firstRow)
      expect(acceptanceTime(42).textContent).toBe(
        formatIndependent(ACCEPTED_AT, readerZone),
      )
      expect(acceptanceTime(43).textContent).toBe(
        formatIndependent(0, readerZone),
      )
      expect(acceptanceTime(43).textContent).not.toBe('')
      expect(acceptanceTime(44).textContent).toBe('—')
      expect(acceptanceTime(45).textContent).toBe('—')
    } finally {
      cleanup()
      reader.release()
    }
  })
})

const preparing = {
  state: 'preparing',
  document: 'terms',
  revisionId: 'current',
  search: 'anna',
  requestedAt: 1000,
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
  records: null,
} as const satisfies HilosLegalAcceptancesExportNode
const ready = {
  state: 'ready',
  document: null,
  revisionId: null,
  search: null,
  requestedAt: 1000,
  finishedAt: 2000,
  expiresAt: 3000,
  sizeBytes: 456,
  records: 12,
} as const satisfies HilosLegalAcceptancesExportNode

/**
 * Mount the page with its export: the section it opens with, the frames after
 * it, and a confirmation step the server skips.
 *
 * @param initial The export the page answer carries, if any.
 */
function mountExport(initial?: HilosLegalAcceptancesExportNode) {
  const frames: Array<(signal: ProjectSignal) => void> = []
  const sent: Array<{ name: string; data: unknown }> = []
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.LEGAL_ACCEPTANCES)
  if (initial !== undefined) page.data.set('legalAcceptancesExport', initial)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') frames.push(listener)
        return () => {}
      },
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
      sendTableFacets() {},
      sendTableRowFocus() {},
    },
    actions: {
      dispatch(name: string, data: unknown) {
        sent.push({ name, data })
        return {
          done: Promise.resolve({
            reply:
              name === 'hilos_step_up_start'
                ? { required: false, purpose: 'export acceptance records' }
                : {},
          }),
        }
      },
    },
  } as unknown as HilosLegalContext
  const view = render(
    <HilosRouterContext.Provider value={harness().router}>
      <HilosLegalAcceptancesPage context={context} />
    </HilosRouterContext.Provider>,
  )
  const find = (id: string) =>
    view.container.querySelector<HTMLElement>(`[data-id="${id}"]`)

  return {
    sent,
    find,
    button: () => find('legal-acceptances-export') as HTMLButtonElement,
    emit(node: HilosLegalAcceptancesExportNode | null) {
      act(() => {
        for (const listener of frames)
          listener({
            type: 'hilos_legal_acceptances_export_state',
            data: { legalAcceptancesExport: node },
          } as unknown as ProjectSignal)
      })
    },
  }
}

describe('HilosLegalAcceptancesPage export (HIL-1234)', () => {
  it('draws the Export button and nothing under it while there is no export', () => {
    const w = mountExport()

    expect(w.button().textContent).toBe('Export')
    expect(w.button().disabled).toBe(false)
    expect(w.find('legal-acceptances-export-filter')).toBeNull()
    expect(w.find('legal-acceptances-export-download')).toBeNull()
  })

  it('draws the three states of the export and the link of a ready one', () => {
    const w = mountExport(preparing)

    expect(w.find('legal-acceptances-export-preparing')?.textContent).toContain(
      'Preparing the export… Started at',
    )
    expect(w.find('legal-acceptances-export-filter')?.textContent).toBe(
      'Terms · current · “anna”',
    )
    expect(w.button().disabled).toBe(true)

    w.emit(ready)
    expect(w.find('legal-acceptances-export-ready')?.textContent).toContain(
      'Export ready · 12 records · 456 B · available until',
    )
    const link = w.find('legal-acceptances-export-download')
    expect(link?.getAttribute('href')).toBe('/_hilos/legal-acceptances-export')
    expect(link?.hasAttribute('download')).toBe(true)
    expect(link?.textContent).toBe('Download')
    expect(w.button().disabled).toBe(false)

    w.emit({ ...ready, state: 'failed', sizeBytes: null, records: null })
    expect(w.find('legal-acceptances-export-failed')?.textContent).toBe(
      'We could not prepare the export.',
    )
    expect(w.find('legal-acceptances-export-download')).toBeNull()
  })

  it('orders the export when the confirmation step is skipped', async () => {
    const w = mountExport()

    await act(async () => {
      fireEvent.click(w.button())
    })

    expect(w.sent.map((entry) => entry.name)).toEqual([
      'hilos_step_up_start',
      'legal_acceptances_export',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      document: null,
      revisionId: null,
      search: null,
    })
  })
})
