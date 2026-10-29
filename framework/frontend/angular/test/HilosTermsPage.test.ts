// The Angular peer of vue/src/public/HilosTermsPage.test.ts and
// react/test/HilosTermsPage.test.tsx: the same cases by the same data-ids. What
// is Angular's own is the mount (TestBed, through a host that projects the
// introduction), and that a frame is read by running change detection.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  bindLegalReconsent,
  bindSessionScope,
  type ActionLifecycle,
  type ActionResult,
  type HilosLegalContext,
} from '@hilos/core'
import type { HilosStaticPageNavigation } from '@hilos/core/base'
import { afterEach, expect, it, vi } from 'vitest'
import {
  first,
  legalContext,
  substantial,
  termsAgreement,
  termsAgreements,
  termsSection,
  termsSectionBehind,
} from '../../core/test/legal/fixtures.js'
import { HilosTermsPage } from '../src/public/HilosTermsPage.js'

@Component({
  imports: [HilosTermsPage],
  template: `<hilos-terms-page [context]="context"
    ><p data-id="terms-intro">A demonstration.</p></hilos-terms-page
  >`,
})
class TermsHost {
  context!: HilosLegalContext
}

let unbind: (() => void) | null = null

afterEach(() => {
  unbind?.()
  unbind = null
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/** An account standing as the wire carries it. */
function standing(facts: Record<string, unknown> = {}) {
  return {
    shown: 'none',
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
    ...facts,
  }
}

/**
 * The one element carrying a data-id, anywhere in the document.
 *
 * @param id The `data-id` the page renders on the node.
 */
function one(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/**
 * Navigation that resolves sibling pages against one project route parameter.
 *
 * @param page The page key marked current.
 * @param project The project route parameter.
 * @param label The accessible name of the tabs row.
 * @returns A static-page navigation fixture.
 */
function publicNavigation(
  page = 'fixture_public_page',
  project = 'first',
  label = 'Public pages',
): HilosStaticPageNavigation {
  return {
    page,
    tabs: [
      { page: 'fixture_public_page', label: 'Public page' },
      { page: 'fixture_history', label: 'Revision history' },
    ],
    params: { project },
    resolvePath: (target, params = {}) =>
      `/projects/${params.project}/${target}`,
    label,
  }
}

/**
 * Mount a Terms page without a host so its navigation input can be updated directly.
 *
 * @returns The mounted Terms page fixture.
 */
function mountBareTerms(): ComponentFixture<HilosTermsPage> {
  const world = legalContext(
    {
      dispatch: vi.fn(() => ({ done: new Promise<ActionResult>(() => {}) })),
    } as unknown as ActionLifecycle,
    { page: 'hilos_terms', data: {} },
  )
  const fixture = TestBed.createComponent(HilosTermsPage)
  fixture.componentRef.setInput('context', world.context)
  document.body.appendChild(fixture.nativeElement as HTMLElement)
  fixture.detectChanges()

  return fixture
}

/**
 * Let the replies land and draw the frame they caused.
 *
 * @param fixture The mounted host.
 */
async function settle(fixture: ComponentFixture<TermsHost>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * The Terms page over an answer, its reader signed in first when one is named.
 *
 * @param data The page data the answer carries.
 * @param reader Bob's standing and whether Ada acts as him, or null for a guest.
 * @param dispatch What the action lifecycle answers.
 */
function mountTerms(
  data: Record<string, unknown>,
  reader: { standing: Record<string, unknown>; impersonated?: boolean } | null,
  dispatch = vi.fn((): { done: Promise<ActionResult> } => ({
    done: new Promise(() => {}),
  })),
) {
  const world = legalContext({ dispatch } as unknown as ActionLifecycle, {
    page: 'hilos_terms',
    data,
  })
  if (reader !== null) {
    bindSessionScope(world.context.connection, world.context.scopes)
    unbind = bindLegalReconsent(world.context.scopes, world.context.actions)
    world.project('handshake_response', {
      data: { accountStanding: reader.standing },
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: reader.impersonated ? { id: 1, name: 'Ada' } : null,
      },
    })
  }
  const fixture = TestBed.createComponent(TermsHost)
  fixture.componentInstance.context = world.context
  document.body.appendChild(fixture.nativeElement as HTMLElement)
  fixture.detectChanges()

  return { world, fixture, dispatch }
}

/** A window inside which Bob holds the first revision. */
const DUE = {
  legalTerms: termsSectionBehind,
  legalAgreements: termsAgreements(
    termsAgreement('window', first, '2026-11-10'),
  ),
}
const IN_WINDOW = standing({
  window: [{ document: 'terms', deadline: '2026-11-10' }],
})

it('renders the heading, the introduction and the loading line without asking anything', async () => {
  const { world, fixture, dispatch } = mountTerms({}, null)
  await settle(fixture)
  expect(
    (fixture.nativeElement as HTMLElement).querySelectorAll('h1'),
  ).toHaveLength(1)
  expect(one('hilos-page-head-title')!.textContent?.trim()).toBe('Terms')
  const icon = one('hilos-page-head-block')?.querySelector(
    'i.bi-file-earmark-text',
  )
  expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
  expect(
    one('hilos-page-head-block')?.querySelector('p')?.textContent?.trim(),
  ).toBe(
    'The terms of using this project: the revision in force and every one before it.',
  )
  expect(one('hilos-breadcrumb')).toBeNull()
  expect(one('hilos-page-tabs')).toBeNull()
  expect(one('terms-intro')!.textContent).toBe('A demonstration.')
  expect(one('terms-loading')!.textContent?.trim()).toBe('Loading the terms…')
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('loading')
  expect(dispatch).not.toHaveBeenCalled()
  expect(world.frames).toEqual([])
})

it('forwards optional navigation to the shared head and updates it without remounting', () => {
  const fixture = mountBareTerms()
  const heading = one('hilos-page-head-title')

  fixture.componentRef.setInput('navigation', publicNavigation())
  fixture.detectChanges()
  const tabs = one('hilos-page-tabs')
  expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
  expect(
    [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
      link.textContent?.trim(),
      link.getAttribute('href'),
    ]),
  ).toEqual([
    ['Public page', '/projects/first/fixture_public_page'],
    ['Revision history', '/projects/first/fixture_history'],
  ])
  expect(
    tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
  ).toBe('hilos-page-tab-fixture_public_page')

  fixture.componentRef.setInput(
    'navigation',
    publicNavigation('fixture_history', 'next', 'More public pages'),
  )
  fixture.detectChanges()
  expect(one('hilos-page-head-title')).toBe(heading)
  expect(tabs?.getAttribute('aria-label')).toBe('More public pages')
  expect(
    tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
  ).toBe('hilos-page-tab-fixture_history')
  expect(
    tabs
      ?.querySelector('[data-id="hilos-page-tab-fixture_history"]')
      ?.getAttribute('href'),
  ).toBe('/projects/next/fixture_history')
})

it('shows a guest the current text with nothing to accept', async () => {
  const { fixture } = mountTerms({ legalTerms: termsSection }, null)
  await settle(fixture)
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('guest')
  expect(one('terms-reader')!.textContent).toContain(
    'in force since 1 October 2026',
  )
  expect(one('terms-text')!.querySelectorAll('li')).toHaveLength(1)
  expect(one('legal-document-accept')).toBeNull()
})

it('confirms in green a reader holding the revision in force', async () => {
  const { fixture } = mountTerms(
    {
      legalTerms: termsSection,
      legalAgreements: termsAgreements(termsAgreement('covered', substantial)),
    },
    { standing: standing() },
  )
  await settle(fixture)
  const reader = one('terms-reader')!
  expect(reader.getAttribute('data-state')).toBe('covered')
  expect(reader.querySelector('.text-success')).not.toBeNull()
  expect(reader.textContent).toContain(
    'You accepted the revision of 1 October 2026',
  )
  expect(one('legal-document-accept')).toBeNull()
})

it('shows the comparison after wording-only changes, without Accept', async () => {
  const { fixture } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(termsAgreement('covered', first)),
    },
    { standing: standing() },
  )
  await settle(fixture)
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('reworded')
  one('legal-document-changes-open')!.click()
  await settle(fixture)
  expect(one('terms-changes-modal')!.textContent).toContain('Old wording')
  expect(one('terms-changes-accept')).toBeNull()
})

it('accepts the Terms alone from the plate while a decision is due', async () => {
  const { fixture, dispatch } = mountTerms(DUE, { standing: IN_WINDOW })
  await settle(fixture)
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('due')
  expect(one('legal-document-reader-plate')).not.toBeNull()
  one('legal-document-accept')!.click()
  expect(dispatch).toHaveBeenCalledWith('hilos_legal_accept', {
    acceptedRevisions: { terms: substantial.revisionId },
  })
})

it('keeps the refusal of an acceptance in its room under the buttons', async () => {
  const { fixture } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(
        termsAgreement('lapsed', first, '2026-10-01'),
      ),
    },
    { standing: standing({ frozen: true, shown: 'frozen' }) },
    vi.fn(() => ({
      done: Promise.reject(
        new ActionError('hilos_legal_accept', 'fail', 'The terms moved.'),
      ),
    })),
  )
  await settle(fixture)
  expect(one('legal-document-reader-plate')!.textContent).toContain(
    'account frozen · deadline passed 1 October 2026',
  )
  one('legal-document-accept')!.click()
  await settle(fixture)
  expect(one('legal-document-accept-refusal')!.textContent).toContain(
    'The terms moved.',
  )
})

it('accepts from the comparison window and closes it on success', async () => {
  let resolve!: (value: ActionResult) => void
  const { fixture, dispatch } = mountTerms(
    DUE,
    { standing: IN_WINDOW },
    vi.fn(() => ({
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await settle(fixture)
  one('legal-document-changes-open')!.click()
  await settle(fixture)
  one('terms-changes-accept')!.click()
  expect(dispatch).toHaveBeenCalledTimes(1)
  resolve({})
  await settle(fixture)
  expect(one('terms-changes-modal')).toBeNull()
})

it('shows the state under a takeover but offers no acceptance', async () => {
  const { fixture } = mountTerms(DUE, {
    standing: IN_WINDOW,
    impersonated: true,
  })
  await settle(fixture)
  expect(one('legal-document-accept')).toBeNull()
  expect(one('legal-document-reader-impersonated')!.textContent?.trim()).toBe(
    'Only Bob can accept the terms.',
  )
})

it('says that no Terms are published, with no reader line and no history', async () => {
  const { fixture } = mountTerms({ legalTerms: null }, null)
  await settle(fixture)
  expect(one('terms-none')!.textContent?.trim()).toBe(
    'This project has not published its terms.',
  )
  expect(one('terms-reader')).toBeNull()
  expect(one('terms-history')).toBeNull()
})
