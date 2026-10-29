import { afterEach, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionError,
  bindLegalReconsent,
  bindSessionScope,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core'
import type { HilosStaticPageNavigation } from '@hilos/core/base'
import {
  clause,
  first,
  legalContext,
  substantial,
  termsAgreement,
  termsAgreements,
  termsSection,
  termsSectionBehind,
} from '../../core/test/legal/fixtures.js'
import { HilosTermsPage } from '../src/public/HilosTermsPage.js'

// The React peer of vue/src/public/HilosTermsPage.test.ts: the same cases by
// the same data-ids. The modals portal to <body>, so the queries read the document.

let unbind: (() => void) | null = null

afterEach(() => {
  cleanup()
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

/** The one element carrying a data-id, anywhere in the document. */
function one(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

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

/** Let the replies and the effects they cause land. */
async function settle(): Promise<void> {
  await act(async () => {})
}

/**
 * The Terms page over an answer, its reader signed in first when one is named.
 *
 * @param data The page data the answer carries.
 * @param reader Bob's standing and whether Ada acts as him, or null for a guest.
 * @param dispatch What the action lifecycle answers.
 */
function drawTerms(
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
  const view = render(
    <HilosTermsPage context={world.context}>
      <p data-id="terms-intro">A demonstration.</p>
    </HilosTermsPage>,
  )

  return { world, dispatch, view }
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
  const { world, dispatch } = drawTerms({}, null)
  await settle()
  expect(document.querySelectorAll('h1')).toHaveLength(1)
  expect(one('hilos-page-head-title')!.textContent).toBe('Terms')
  const icon = one('hilos-page-head-block')?.querySelector(
    'i.bi-file-earmark-text',
  )
  expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
  expect(one('hilos-page-head-block')?.querySelector('p')?.textContent).toBe(
    'The terms of using this project: the revision in force and every one before it.',
  )
  expect(one('hilos-breadcrumb')).toBeNull()
  expect(one('hilos-page-tabs')).toBeNull()
  expect(one('terms-intro')!.textContent).toBe('A demonstration.')
  expect(one('terms-loading')!.textContent).toBe('Loading the terms…')
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('loading')
  expect(dispatch).not.toHaveBeenCalled()
  expect(world.frames).toEqual([])
})

it('forwards optional navigation to the shared head and updates it without remounting', async () => {
  const { world, view } = drawTerms({}, null)
  await settle()
  const heading = one('hilos-page-head-title')

  view.rerender(
    <HilosTermsPage context={world.context} navigation={publicNavigation()}>
      <p data-id="terms-intro">A demonstration.</p>
    </HilosTermsPage>,
  )
  const tabs = one('hilos-page-tabs')
  expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
  expect(
    [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
      link.textContent,
      link.getAttribute('href'),
    ]),
  ).toEqual([
    ['Public page', '/projects/first/fixture_public_page'],
    ['Revision history', '/projects/first/fixture_history'],
  ])
  expect(
    tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
  ).toBe('hilos-page-tab-fixture_public_page')

  view.rerender(
    <HilosTermsPage
      context={world.context}
      navigation={publicNavigation(
        'fixture_history',
        'next',
        'More public pages',
      )}
    >
      <p data-id="terms-intro">A demonstration.</p>
    </HilosTermsPage>,
  )
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

it('shows a guest the text and the history, newest first, with nothing to accept', async () => {
  drawTerms({ legalTerms: termsSection }, null)
  await settle()
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('guest')
  expect(one('terms-reader')!.textContent).toContain(
    'in force since 1 October 2026',
  )
  expect(one('terms-text')!.querySelectorAll('li')).toHaveLength(1)
  const revisions = Array.from(
    document.querySelectorAll('[data-id="terms-history-revision"]'),
  )
  expect(revisions.map((row) => row.getAttribute('data-revision'))).toEqual([
    substantial.revisionId,
    '2026-09-27',
    first.revisionId,
  ])
  expect(
    revisions[0]!.querySelector('[data-id="terms-history-current"]'),
  ).not.toBeNull()
  expect(one('terms-history-accepted')).toBeNull()
  expect(one('legal-document-accept')).toBeNull()
})

it('confirms in green a reader holding the revision in force', async () => {
  drawTerms(
    {
      legalTerms: termsSection,
      legalAgreements: termsAgreements(termsAgreement('covered', substantial)),
    },
    { standing: standing() },
  )
  await settle()
  const reader = one('terms-reader')!
  expect(reader.getAttribute('data-state')).toBe('covered')
  expect(reader.querySelector('.text-success')).not.toBeNull()
  expect(reader.textContent).toContain(
    'You accepted the revision of 1 October 2026',
  )
  expect(
    document.querySelector(
      `[data-revision="${substantial.revisionId}"] [data-id="terms-history-accepted"]`,
    ),
  ).not.toBeNull()
  expect(one('legal-document-accept')).toBeNull()
})

it('shows the comparison after wording-only changes, without Accept', async () => {
  drawTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(termsAgreement('covered', first)),
    },
    { standing: standing() },
  )
  await settle()
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('reworded')
  fireEvent.click(one('legal-document-changes-open')!)
  await settle()
  expect(one('terms-changes-modal')!.textContent).toContain('Old wording')
  expect(one('terms-changes-accept')).toBeNull()
})

it('accepts the Terms alone from the plate while a decision is due', async () => {
  const { dispatch } = drawTerms(DUE, { standing: IN_WINDOW })
  await settle()
  expect(one('terms-reader')!.getAttribute('data-state')).toBe('due')
  expect(one('legal-document-reader-plate')).not.toBeNull()
  fireEvent.click(one('legal-document-accept')!)
  expect(dispatch).toHaveBeenCalledWith('hilos_legal_accept', {
    acceptedRevisions: { terms: substantial.revisionId },
  })
})

it('keeps the refusal of an acceptance in its room under the buttons', async () => {
  drawTerms(
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
  await settle()
  expect(one('legal-document-reader-plate')!.textContent).toContain(
    'account frozen · deadline passed 1 October 2026',
  )
  fireEvent.click(one('legal-document-accept')!)
  await settle()
  expect(one('legal-document-accept-refusal')!.textContent).toContain(
    'The terms moved.',
  )
})

it('accepts from the comparison window and closes it on success', async () => {
  let resolve!: (value: ActionResult) => void
  const { dispatch } = drawTerms(
    DUE,
    { standing: IN_WINDOW },
    vi.fn(() => ({
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await settle()
  fireEvent.click(one('legal-document-changes-open')!)
  await settle()
  const comparison = one('terms-changes-modal')!
  const body = comparison.closest('fieldset')!
  const text = comparison.textContent
  const accept = one('terms-changes-accept')!
  expect(body.disabled).toBe(false)
  accept.focus()
  fireEvent.click(accept)
  expect(comparison.closest('fieldset')).toBe(body)
  expect(body.disabled).toBe(true)
  expect(comparison.textContent).toBe(text)
  expect(accept.matches(':disabled')).toBe(false)
  expect(accept.getAttribute('aria-busy')).toBe('true')
  expect(document.activeElement).toBe(accept)
  for (const id of ['terms-changes-close', 'modal-close']) {
    expect(one(id)!.matches(':disabled')).toBe(true)
    fireEvent.click(one(id)!)
  }
  fireEvent.click(one('modal')!)
  fireEvent.keyDown(one('modal')!, { key: 'Escape' })
  fireEvent.click(accept)
  expect(one('terms-changes-modal')).toBe(comparison)
  expect(dispatch).toHaveBeenCalledTimes(1)
  await act(async () => {
    resolve({})
  })
  await settle()
  expect(one('terms-changes-modal')).toBeNull()
})

it('keeps the comparison and refusal after failure, then permits a fresh attempt', async () => {
  let refuse!: (reason: unknown) => void
  let answer!: (value: ActionResult) => void
  const { dispatch } = drawTerms(
    DUE,
    { standing: IN_WINDOW },
    vi.fn(() => ({
      done: new Promise<ActionResult>((resolve, reject) => {
        answer = resolve
        refuse = reject
      }),
    })),
  )
  await settle()
  fireEvent.click(one('legal-document-changes-open')!)
  const comparison = one('terms-changes-modal')!
  const body = comparison.closest('fieldset')!
  fireEvent.click(one('terms-changes-accept')!)
  expect(body.disabled).toBe(true)
  await act(async () =>
    refuse(new ActionError('hilos_legal_accept', 'fail', 'The terms moved.')),
  )
  expect(one('terms-changes-modal')).toBe(comparison)
  expect(comparison.closest('fieldset')).toBe(body)
  expect(body.disabled).toBe(false)
  expect(one('terms-changes-refusal')!.textContent).toContain(
    'The terms moved.',
  )
  expect(one('terms-changes-refusal-details')!.matches(':disabled')).toBe(false)
  expect(one('terms-changes-close')!.matches(':disabled')).toBe(false)
  expect(one('modal-close')!.matches(':disabled')).toBe(false)
  expect(dispatch).toHaveBeenCalledTimes(1)
  fireEvent.click(one('terms-changes-accept')!)
  expect(body.disabled).toBe(true)
  expect(dispatch).toHaveBeenCalledTimes(2)
  await act(async () => answer({}))
  expect(one('terms-changes-modal')).toBeNull()
})

it('opens an older revision through the page own read', async () => {
  let resolve!: (value: ActionResult) => void
  const { dispatch } = drawTerms(
    { legalTerms: termsSection },
    null,
    vi.fn(() => ({
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await settle()
  fireEvent.click(
    document.querySelector(
      `[data-revision="${first.revisionId}"] [data-id="terms-history-open"]`,
    )!,
  )
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_terms_revision_text',
    { document: 'terms', revisionId: first.revisionId },
    expect.anything(),
  )
  await act(async () => {
    resolve({
      reply: {
        document: 'terms',
        revisionId: first.revisionId,
        clauses: [clause],
      },
    })
  })
  await settle()
  expect(one('terms-revision-modal')!.textContent).toContain(clause.statement)
})

it('shows the state under a takeover but offers no acceptance', async () => {
  drawTerms(DUE, { standing: IN_WINDOW, impersonated: true })
  await settle()
  expect(one('legal-document-accept')).toBeNull()
  expect(one('legal-document-reader-impersonated')!.textContent).toBe(
    'Only Bob can accept the terms.',
  )
})

it('says that no Terms are published, with no reader line and no history', async () => {
  drawTerms({ legalTerms: null }, null)
  await settle()
  expect(one('terms-none')!.textContent).toBe(
    'This project has not published its terms.',
  )
  expect(one('terms-reader')).toBeNull()
  expect(one('terms-history')).toBeNull()
})
