import { consentTermsWithClauses } from '../../core/test/legal/consentFixture.js'
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  ScopeManager,
  createSignal,
  type HilosLegalContext,
  type HilosRouter,
} from '@hilos/core'
import { HilosLegalPage } from '../src/admin/legal/HilosLegalPage.js'
import { HilosLegalDocumentPage } from '../src/admin/legal/HilosLegalDocumentPage.js'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'

/** Real reactive page data with the transport replaced at its boundary. */
function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage('hilos_legal')
  const context = {
    scopes,
    connection: {
      on: () => () => {},
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
    },
    actions: {},
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: 'hilos_legal',
      params: { documentKey: 'terms', revisionId: 'current' },
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return { scope, context, router }
}

afterEach(cleanup)
describe('legal admin React pages', () => {
  it.each([HilosLegalPage, HilosLegalDocumentPage, HilosLegalRevisionPage])(
    'replaces declaration content with the catalog refusal',
    (View) => {
      const h = harness()
      const view = render(
        <HilosRouterContext.Provider value={h.router}>
          <View context={h.context} />
        </HilosRouterContext.Provider>,
      )
      act(() => {
        h.scope.data.set(
          'legalCatalogRefusal',
          'The catalog names a missing clause',
        )
      })
      expect(
        view.container.querySelector('[data-id="legal-catalog-refusal"]')
          ?.textContent,
      ).toContain('The catalog names a missing clause')
      expect(view.container.querySelector('[data-id="legal-set"]')).toBeNull()
      expect(
        view.container.querySelector('[data-id="legal-revision-text"]'),
      ).toBeNull()
    },
  )
})

it('opens both consent documents in a read-only React preview', async () => {
  const h = harness()
  h.scope.data.set('legalDocument', {
    document: 'terms',
    declared: true,
    revision: {
      revisionId: 'current',
      publishedOn: '2026-09-17',
      effectiveOn: '2026-09-17',
      significance: 'substantial',
      setVersion: 1,
      deviationCount: 0,
    },
    set: null,
    newerSet: null,
    deviations: [],
  })
  Object.assign(h.context.actions, {
    dispatch: () => ({
      done: Promise.resolve({ reply: consentTermsWithClauses() }),
    }),
  })
  render(
    <HilosRouterContext.Provider value={h.router}>
      <HilosLegalDocumentPage context={h.context} />
    </HilosRouterContext.Provider>,
  )
  await act(async () => {
    fireEvent.click(
      document.querySelector('[data-id="legal-preview-consent"]')!,
    )
  })
  const preview = document.querySelector('[data-id="legal-consent-preview"]')!
  expect(
    preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
  ).toHaveLength(4)
  expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
  fireEvent.click(
    document.querySelector('[data-id="legal-consent-preview-close"]')!,
  )
  expect(document.querySelector('[data-id="legal-consent-preview"]')).toBeNull()
})

it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', () => {
  const h = harness()
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  Object.assign(h.context.connection, {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
  })
  const revision = {
    revisionId: 'current',
    publishedOn: '2026-09-27',
    effectiveOn: '2026-09-27',
    significance: 'editorial',
    setVersion: 1,
    deviationCount: 0,
  }
  const view = render(
    <HilosRouterContext.Provider value={h.router}>
      <HilosLegalPage context={h.context} />
    </HilosRouterContext.Provider>,
  )

  act(() => {
    for (const listener of windowListeners) {
      listener({
        data: {
          page: 'hilos_legal',
          tableKey: 'hilosLegalDocuments',
          rows: [
            { rowKey: 'terms', covered: 1, window: 0, lapsed: 2 },
            { rowKey: 'privacy', covered: 3, window: 0, lapsed: 0 },
          ].map(({ rowKey, ...counts }) => ({
            rowKey,
            slots: { document: { declared: true, revision, ...counts } },
          })),
          totalCount: 2,
        },
      })
    }
  })

  // The table draws each cell twice — its rows and its narrow-screen cards — so
  // every copy is checked, not the first.
  const links = Array.from(
    view.container.querySelectorAll('[data-id="legal-count-lapsed-link"]'),
  )
  expect(links.length).toBeGreaterThan(0)
  for (const link of links) {
    expect(link.getAttribute('href')).toBe('/hilos/users/terms')
    expect(link.getAttribute('data-document')).toBe('terms')
    expect(
      link.querySelector('[data-id="legal-count-lapsed"]')?.textContent,
    ).toBe('2')
  }
  const unlinked = Array.from(
    view.container.querySelectorAll('[data-id="legal-count-lapsed"]'),
  )
    .filter((count) => count.closest('a') === null)
    .map((count) => count.textContent)
  expect(unlinked.length).toBeGreaterThan(0)
  expect(new Set(unlinked)).toEqual(new Set(['0']))
})

it('says "Past deadline" beside the count while the refusal setting is hidden (HIL-1260)', () => {
  const h = harness()
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  Object.assign(h.context.connection, {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
  })
  const revision = {
    revisionId: 'current',
    publishedOn: '2026-09-27',
    effectiveOn: '2026-09-27',
    significance: 'editorial',
    setVersion: 1,
    deviationCount: 0,
  }
  const view = render(
    <HilosRouterContext.Provider value={h.router}>
      <HilosLegalPage context={h.context} />
    </HilosRouterContext.Provider>,
  )

  const label = () =>
    view.container
      .querySelector('[data-id="legal-count-lapsed"]')
      ?.closest('td')?.textContent

  act(() => {
    for (const listener of windowListeners) {
      listener({
        data: {
          page: 'hilos_legal',
          tableKey: 'hilosLegalDocuments',
          rows: [
            {
              rowKey: 'terms',
              slots: {
                document: {
                  declared: true,
                  revision,
                  covered: 1,
                  window: 0,
                  lapsed: 2,
                },
              },
            },
          ],
          totalCount: 1,
        },
      })
    }
  })
  expect(label()).toContain('Frozen')

  act(() => {
    for (const listener of windowListeners) {
      listener({
        data: {
          page: 'hilos_legal',
          tableKey: 'hilosLegalSettings',
          rows: [
            {
              rowKey: 'legal.refusal_after_deadline',
              slots: {
                setting: {
                  value: { _hidden: true },
                  defaultValue: { _hidden: true },
                },
              },
            },
          ],
          totalCount: 1,
        },
      })
    }
  })
  expect(label()).toContain('Past deadline')
})
