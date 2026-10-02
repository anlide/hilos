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

it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', async () => {
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
  // The link follows the navigator's current path, which the shared harness
  // leaves out.
  await TestBed.configureTestingModule({
    imports: [HilosLegalPage],
    providers: [
      {
        provide: HILOS_ROUTER,
        useValue: { ...h.router, currentPath: createSignal('') },
      },
    ],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()

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
  fixture.detectChanges()

  const element = fixture.nativeElement as HTMLElement
  // The table draws each cell twice — its rows and its narrow-screen cards — so
  // every copy is checked, not the first.
  const links = Array.from(
    element.querySelectorAll('[data-id="legal-count-lapsed-link"]'),
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
    element.querySelectorAll('[data-id="legal-count-lapsed"]'),
  )
    .filter((count) => count.closest('a') === null)
    .map((count) => count.textContent)
  expect(unlinked.length).toBeGreaterThan(0)
  expect(new Set(unlinked)).toEqual(new Set(['0']))
  fixture.destroy()
})

it('says "Past deadline" beside the count while the refusal setting is hidden (HIL-1260)', async () => {
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
  await TestBed.configureTestingModule({
    imports: [HilosLegalPage],
    providers: [
      {
        provide: HILOS_ROUTER,
        useValue: { ...h.router, currentPath: createSignal('') },
      },
    ],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()

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
  fixture.detectChanges()

  const element = fixture.nativeElement as HTMLElement
  // The words stand beside the count in every copy of the cell — its row and
  // its narrow-screen card.
  const labels = () => {
    const links = Array.from(
      element.querySelectorAll('[data-id="legal-count-lapsed-link"]'),
    )
    expect(links.length).toBeGreaterThan(0)
    return new Set(
      links.map((link) => link.nextElementSibling?.textContent?.trim()),
    )
  }
  expect(labels()).toEqual(new Set(['Frozen']))

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
  fixture.detectChanges()

  expect(labels()).toEqual(new Set(['Past deadline']))
  fixture.destroy()
})
