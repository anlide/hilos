import { consentTermsWithClauses } from '../../core/test/legal/consentFixture.js'
import { afterEach, describe, expect, it } from 'vitest'
import { type Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
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

afterEach(() => TestBed.resetTestingModule())
describe('legal admin Angular pages', () => {
  it.each([HilosLegalPage, HilosLegalDocumentPage, HilosLegalRevisionPage])(
    'replaces declaration content with the catalog refusal',
    async (component) => {
      const h = harness()
      await TestBed.configureTestingModule({
        imports: [component],
        providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
      }).compileComponents()
      const fixture = TestBed.createComponent(component as Type<unknown>)
      fixture.componentRef.setInput('context', h.context)
      fixture.detectChanges()
      await fixture.whenStable()
      h.scope.data.set(
        'legalCatalogRefusal',
        'The catalog names a missing clause',
      )
      fixture.detectChanges()
      const element = fixture.nativeElement as HTMLElement
      expect(
        element.querySelector('[data-id="legal-catalog-refusal"]')?.textContent,
      ).toContain('The catalog names a missing clause')
      expect(element.querySelector('[data-id="legal-set"]')).toBeNull()
      expect(
        element.querySelector('[data-id="legal-revision-text"]'),
      ).toBeNull()
      fixture.destroy()
    },
  )
})

it('opens both consent documents in a read-only Angular preview', async () => {
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
  await TestBed.configureTestingModule({
    imports: [HilosLegalDocumentPage],
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalDocumentPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()
  ;(
    fixture.nativeElement.querySelector(
      '[data-id="legal-preview-consent"]',
    ) as HTMLElement
  ).click()
  fixture.detectChanges()
  await Promise.resolve()
  await Promise.resolve()
  await fixture.whenStable()
  fixture.detectChanges()
  const preview = (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="legal-consent-preview"]',
  )!
  expect(preview.textContent).toContain('Project retention')
  expect(
    preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
  ).toHaveLength(4)
  expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
  ;(
    fixture.nativeElement.querySelector(
      '[data-id="legal-consent-preview-close"]',
    ) as HTMLElement
  ).click()
  fixture.detectChanges()
  await fixture.whenStable()
  expect(
    fixture.nativeElement.querySelector('[data-id="legal-consent-preview"]'),
  ).toBeNull()
})
