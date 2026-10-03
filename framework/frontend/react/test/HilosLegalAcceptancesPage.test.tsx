import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalAcceptancesPage } from '../src/admin/legal/HilosLegalAcceptancesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosLegalAcceptancesExportNode,
  type HilosLegalContext,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'

/** The acceptance's person fields, as the server sent them to this viewer. */
interface PersonFields {
  name: unknown
  email: unknown
}

/** The live window is real; only its transport is replaced, and the rows arrive by `push`. */
function harness() {
  const listeners: Array<(frame: never) => void> = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LEGAL_ACCEPTANCES)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        if (event === 'tableWindow') listeners.push(listener)
        return () => {}
      },
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
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
    push({ name, email }: PersonFields) {
      for (const listener of listeners)
        listener({
          data: {
            page: HilosPages.LEGAL_ACCEPTANCES,
            tableKey: 'hilosLegalAcceptances',
            rows: [
              {
                rowKey: 42,
                slots: {
                  acceptance: {
                    userId: 9,
                    name,
                    email,
                    document: 'terms',
                    revisionId: 'current',
                    declared: true,
                    acceptedAt: '2026-09-27 12:00:00',
                  },
                },
              },
            ],
            totalCount: 1,
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
  act(() => h.push(person))
  return view.container.querySelector(
    '[data-id="legal-acceptance-row"]',
  ) as HTMLElement
}

afterEach(cleanup)

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
