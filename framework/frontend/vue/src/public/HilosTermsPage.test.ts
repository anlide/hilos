import {
  ActionError,
  bindLegalReconsent,
  bindSessionScope,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import {
  clause,
  first,
  legalContext,
  substantial,
  termsAgreement,
  termsAgreements,
  termsSection,
  termsSectionBehind,
} from '../../../core/test/legal/fixtures.js'
import HilosTermsPage from './HilosTermsPage.vue'

let unbind: (() => void) | null = null

afterEach(() => {
  unbind?.()
  unbind = null
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

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
  const view = mount(HilosTermsPage, {
    attachTo: document.body,
    props: { context: world.context },
    slots: { default: '<p data-id="terms-intro">A demonstration.</p>' },
  })

  return { world, view, dispatch }
}

it('renders the heading, the introduction and the loading line without asking anything', async () => {
  const { world, view, dispatch } = mountTerms({}, null)
  await flushPromises()
  expect(view.get('[data-id="static-page-title"]').text()).toBe('Terms')
  expect(view.get('[data-id="terms-intro"]').text()).toBe('A demonstration.')
  expect(view.get('[data-id="terms-loading"]').text()).toBe(
    'Loading the terms…',
  )
  expect(view.get('[data-id="terms-reader"]').attributes('data-state')).toBe(
    'loading',
  )
  expect(dispatch).not.toHaveBeenCalled()
  expect(world.frames).toEqual([])
})

it('shows a guest the text and the history, newest first, with nothing to accept', async () => {
  const { view } = mountTerms({ legalTerms: termsSection }, null)
  await flushPromises()
  const reader = view.get('[data-id="terms-reader"]')
  expect(reader.attributes('data-state')).toBe('guest')
  expect(reader.text()).toContain('in force since 1 October 2026')
  expect(view.findAll('[data-id="terms-text"] li')).toHaveLength(1)
  const revisions = view.findAll('[data-id="terms-history-revision"]')
  expect(revisions.map((row) => row.attributes('data-revision'))).toEqual([
    substantial.revisionId,
    '2026-09-27',
    first.revisionId,
  ])
  expect(revisions[0]!.find('[data-id="terms-history-current"]').exists()).toBe(
    true,
  )
  expect(view.find('[data-id="terms-history-accepted"]').exists()).toBe(false)
  expect(view.find('[data-id="terms-accept"]').exists()).toBe(false)
})

it('confirms in green a reader holding the revision in force', async () => {
  const { view } = mountTerms(
    {
      legalTerms: termsSection,
      legalAgreements: termsAgreements(termsAgreement('covered', substantial)),
    },
    { standing: standing() },
  )
  await flushPromises()
  const reader = view.get('[data-id="terms-reader"]')
  expect(reader.attributes('data-state')).toBe('covered')
  expect(reader.find('.text-success').exists()).toBe(true)
  expect(reader.text()).toContain('You accepted the revision of 1 October 2026')
  expect(
    view
      .get(`[data-revision="${substantial.revisionId}"]`)
      .find('[data-id="terms-history-accepted"]')
      .exists(),
  ).toBe(true)
  expect(view.find('[data-id="terms-accept"]').exists()).toBe(false)
})

it('shows the comparison after wording-only changes, without Accept', async () => {
  const { view } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(termsAgreement('covered', first)),
    },
    { standing: standing() },
  )
  await flushPromises()
  expect(view.get('[data-id="terms-reader"]').attributes('data-state')).toBe(
    'reworded',
  )
  await view.get('[data-id="terms-changes-open"]').trigger('click')
  await flushPromises()
  expect(
    document.querySelector('[data-id="terms-changes-modal"]')?.textContent,
  ).toContain('Old wording')
  expect(document.querySelector('[data-id="terms-changes-accept"]')).toBeNull()
})

it('accepts the Terms alone from the plate while a decision is due', async () => {
  const { view, dispatch } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(
        termsAgreement('window', first, '2026-11-10'),
      ),
    },
    {
      standing: standing({
        window: [{ document: 'terms', deadline: '2026-11-10' }],
      }),
    },
  )
  await flushPromises()
  expect(view.get('[data-id="terms-reader"]').attributes('data-state')).toBe(
    'due',
  )
  expect(view.find('[data-id="terms-reader-plate"]').exists()).toBe(true)
  await view.get('[data-id="terms-accept"]').trigger('click')
  expect(dispatch).toHaveBeenCalledWith('hilos_legal_accept', {
    acceptedRevisions: { terms: substantial.revisionId },
  })
})

it('keeps the refusal of an acceptance in its room under the buttons', async () => {
  const { view } = mountTerms(
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
  await flushPromises()
  expect(view.get('[data-id="terms-reader-plate"]').text()).toContain(
    'account frozen · deadline passed 1 October 2026',
  )
  await view.get('[data-id="terms-accept"]').trigger('click')
  await flushPromises()
  expect(view.get('[data-id="terms-accept-refusal"]').text()).toContain(
    'The terms moved.',
  )
})

it('accepts from the comparison window and closes it on success', async () => {
  let resolve!: (value: ActionResult) => void
  const { view, dispatch } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(
        termsAgreement('window', first, '2026-11-10'),
      ),
    },
    {
      standing: standing({
        window: [{ document: 'terms', deadline: '2026-11-10' }],
      }),
    },
    vi.fn(() => ({
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await flushPromises()
  await view.get('[data-id="terms-changes-open"]').trigger('click')
  await flushPromises()
  const accept = document.querySelector<HTMLButtonElement>(
    '[data-id="terms-changes-accept"]',
  )
  accept!.click()
  expect(dispatch).toHaveBeenCalledTimes(1)
  resolve({})
  await flushPromises()
  expect(document.querySelector('[data-id="terms-changes-modal"]')).toBeNull()
})

it('opens an older revision through the page own read', async () => {
  let resolve!: (value: ActionResult) => void
  const { view, dispatch } = mountTerms(
    { legalTerms: termsSection },
    null,
    vi.fn(() => ({
      done: new Promise<ActionResult>((done) => {
        resolve = done
      }),
    })),
  )
  await flushPromises()
  await view
    .get(`[data-revision="${first.revisionId}"] [data-id="terms-history-open"]`)
    .trigger('click')
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_terms_revision_text',
    { document: 'terms', revisionId: first.revisionId },
    expect.anything(),
  )
  resolve({
    reply: {
      document: 'terms',
      revisionId: first.revisionId,
      clauses: [clause],
    },
  })
  await flushPromises()
  expect(
    document.querySelector('[data-id="terms-revision-modal"]')?.textContent,
  ).toContain(clause.statement)
})

it('shows the state under a takeover but offers no acceptance', async () => {
  const { view } = mountTerms(
    {
      legalTerms: termsSectionBehind,
      legalAgreements: termsAgreements(
        termsAgreement('window', first, '2026-11-10'),
      ),
    },
    {
      standing: standing({
        window: [{ document: 'terms', deadline: '2026-11-10' }],
      }),
      impersonated: true,
    },
  )
  await flushPromises()
  expect(view.find('[data-id="terms-accept"]').exists()).toBe(false)
  expect(view.get('[data-id="terms-reader-impersonated"]').text()).toBe(
    'Only Bob can accept the terms.',
  )
})

it('says that no Terms are published, with no reader line and no history', async () => {
  const { view } = mountTerms({ legalTerms: null }, null)
  await flushPromises()
  expect(view.get('[data-id="terms-none"]').text()).toBe(
    'This project has not published its terms.',
  )
  expect(view.find('[data-id="terms-reader"]').exists()).toBe(false)
  expect(view.find('[data-id="terms-history"]').exists()).toBe(false)
})
