import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosLegalAcceptancesPage } from '../src/admin/legal/HilosLegalAcceptancesPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosLegalAcceptanceRow,
  type HilosLegalContext,
  type HilosRouter,
} from '@hilos/core'

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
  // The person's link follows the navigator's current path.
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_ACCEPTANCES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return {
    context,
    router,
    /**
     * One acceptance record, with the person's name and email as the server
     * sent them.
     *
     * @param name The person's name, or the hidden mark in its place.
     * @param email The person's verified email, null, or the hidden mark.
     */
    push(
      name: HilosLegalAcceptanceRow['name'],
      email: HilosLegalAcceptanceRow['email'],
    ) {
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

/**
 * Mount the page over one acceptance record and return its drawn person cell.
 *
 * @param name The person's name, or the hidden mark in its place.
 * @param email The person's verified email, null, or the hidden mark.
 */
async function mountOver(
  name: HilosLegalAcceptanceRow['name'],
  email: HilosLegalAcceptanceRow['email'],
): Promise<HTMLElement> {
  const h = harness()
  await TestBed.configureTestingModule({
    imports: [HilosLegalAcceptancesPage],
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalAcceptancesPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()
  h.push(name, email)
  fixture.detectChanges()
  return (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="legal-acceptance-row"]',
  ) as HTMLElement
}

afterEach(() => TestBed.resetTestingModule())
describe('HilosLegalAcceptancesPage (HIL-1260)', () => {
  it('draws the mark for a hidden name and a hidden email, and says nothing about email', async () => {
    const record = await mountOver(HIDDEN_VALUE, HIDDEN_VALUE)

    expect(record.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(record.textContent).not.toContain('No verified email')
  })

  it('says "No verified email" only when there is none', async () => {
    const record = await mountOver('Reader', null)

    expect(record.querySelector('strong')?.textContent?.trim()).toBe('Reader')
    expect(record.textContent).toContain('No verified email')
    expect(record.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })
})
