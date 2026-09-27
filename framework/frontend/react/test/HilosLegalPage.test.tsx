import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
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
