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
