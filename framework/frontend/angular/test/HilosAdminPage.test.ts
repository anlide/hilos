// The Angular peer of vue/src/HilosAdminPage.test.ts and
// react/test/HilosAdminPage.test.tsx: the shell draws the cards to a page's
// children whenever it has any, a page's own projected content beneath them, and
// the stub only for a leaf that projects nothing.
import { Component, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HilosPages, createSignal } from '@hilos/core'
import type {
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** The identity a section answers with: a chain above it and cards below. */
const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Internationalization',
  lead: 'Languages, countries, and translation screens.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
  ],
  children: [
    {
      page: HilosPages.I18N_LANGUAGES,
      label: 'Languages',
      lead: 'The languages the product ships in.',
      icon: null,
    },
  ],
}

/** The identity a leaf answers with: a chain above it and nothing below. */
const LEAF_IDENTITY: HilosPageIdentity = {
  label: 'Language',
  lead: 'A single language.',
  breadcrumb: [{ page: HilosPages.I18N_LANGUAGE, label: 'Language' }],
  children: [],
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A page that projects nothing into the shell. */
@Component({
  selector: 'test-admin-bare-host',
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" />`,
})
class BareHost {
  page: string = HilosPages.I18N
}

/** A page that projects content of its own into the shell. */
@Component({
  selector: 'test-admin-content-host',
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page [page]="page">
      <p data-id="page-body">Content of this page</p>
    </hilos-admin-page>
  `,
})
class ContentHost {
  page: string = HilosPages.I18N
}

function mount<T>(
  host: Type<T>,
  identity: HilosPageIdentity | undefined,
): ComponentFixture<T> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router(identity) }],
  })
  const fixture = TestBed.createComponent(host)
  fixture.detectChanges()

  return fixture
}

function el(fixture: ComponentFixture<unknown>, id: string): Element | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosAdminPage', () => {
  it('renders the heading, breadcrumb, and child cards a section answered with', () => {
    const fixture = mount(BareHost, SECTION_IDENTITY)

    expect(el(fixture, 'hilos-admin-title')?.textContent).toContain(
      'Internationalization',
    )
    expect(el(fixture, 'hilos-breadcrumb')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-children')).not.toBeNull()
    expect(
      el(fixture, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`),
    ).not.toBeNull()
  })

  it('renders the empty stub for a leaf', () => {
    const fixture = mount(BareHost, LEAF_IDENTITY)

    expect(el(fixture, 'hilos-admin-empty')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-children')).toBeNull()
  })

  it('draws a skeleton and nothing else while the name is still on the wire', () => {
    // The empty h1 under the same data-id is what this rules out: a test could
    // not tell "the name did not arrive" from "the name arrived empty".
    const fixture = mount(BareHost, undefined)

    expect(el(fixture, 'hilos-admin-title-skeleton')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-title')).toBeNull()
    expect(el(fixture, 'hilos-breadcrumb')).toBeNull()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()
    // The page key is internal and never printed, least of all as a heading.
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(
      HilosPages.I18N,
    )
  })

  it('keeps the child cards above the content a page provides', () => {
    const fixture = mount(ContentHost, SECTION_IDENTITY)

    const cards = el(fixture, 'hilos-admin-children')
    const body = el(fixture, 'page-body')
    if (cards === null || body === null) {
      throw new Error(
        'A page with children draws both the cards and its content.',
      )
    }

    // A page with children needs both, so the order is the contract and not an
    // accident: the way onward stays above the content it is offered alongside.
    expect(
      cards.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })

  it("draws no stub under a leaf's own content", () => {
    const fixture = mount(ContentHost, LEAF_IDENTITY)

    expect(el(fixture, 'page-body')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()
    expect(el(fixture, 'hilos-admin-children')).toBeNull()
  })
})
