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
