import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalAcceptancesPage } from '../src/admin/legal/HilosLegalAcceptancesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
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
