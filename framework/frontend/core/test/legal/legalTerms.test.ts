import { describe, expect, it } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import { createHilosLegalRevisionReader } from '../../src/legal/legalRevisions.js'
import {
  createHilosLegalTermsStore,
  describeHilosLegalTermsReader,
  legalTermsSectionSchema,
  TERMS_REVISION_TEXT_ACTION,
} from '../../src/legal/legalTerms.js'
import {
  current,
  first,
  legalContext,
  substantial,
  termsAgreement,
  termsAgreements,
  termsSection,
  termsSectionBehind,
} from './fixtures.js'

/** The midnight 1 November 2026 starts at, UTC: 9 days before a 10 November deadline. */
const NOVEMBER_FIRST = Date.UTC(2026, 10, 1)

/** Bob reads the page himself. */
const BOB = { name: 'Bob', impersonated: false }

/** An action lifecycle whose replies the test settles by hand. */
function pendingActions() {
  const requests: {
    name: string
    payload: unknown
    resolve(value: ActionResult): void
    reject(error: Error): void
  }[] = []
  const actions = {
    dispatch(name: string, payload: unknown) {
      return {
        done: new Promise<ActionResult>((resolve, reject) =>
          requests.push({ name, payload, resolve, reject }),
        ),
      }
    },
  } as unknown as ActionLifecycle

  return { actions, requests }
}

/**
 * The Terms page open over a signed-in reader's answer, with its store started.
 *
 * @param agreement The reader's Terms standing.
 * @param section The Terms section the answer carries.
 */
function termsWorld(
  agreement = termsAgreement('window', first, '2026-11-10'),
  section: unknown = termsSectionBehind,
) {
  const { actions, requests } = pendingActions()
  const world = legalContext(actions, {
    page: 'hilos_terms',
    data: { legalTerms: section, legalAgreements: termsAgreements(agreement) },
  })
  const store = createHilosLegalTermsStore(world.context)
  const stop = store.start()

  return { ...world, requests, store, stop }
}

/** The page_subscribe frames the store sent, of every frame the connection took. */
function resubscribes(frames: readonly unknown[]): unknown[] {
  return frames.filter(
    (frame) => (frame as { type?: string }).type === 'page_subscribe',
  )
}

describe('the Terms section on the wire (HIL-501)', () => {
  it('reads a missing Terms, a guest answer and a reader behind, and drops garbage', () => {
    expect(legalTermsSectionSchema.parse(null)).toBeNull()
    expect(legalTermsSectionSchema.parse(termsSection)).toEqual(termsSection)
    expect(
      legalTermsSectionSchema.parse(termsSectionBehind)?.changes
        ?.fromRevisionId,
    ).toBe(first.revisionId)
    expect(
      legalTermsSectionSchema.safeParse({ ...termsSection, current: null })
        .success,
    ).toBe(false)
    expect(
      legalTermsSectionSchema.safeParse({ ...termsSection, changes: undefined })
        .success,
    ).toBe(false)
  })
})

describe('the reader line over the text', () => {
  const reader = { person: BOB, frozen: false, now: NOVEMBER_FIRST }

  it('waits for the answer, and for the standing of the person in the tab', () => {
    expect(
      describeHilosLegalTermsReader(undefined, undefined, reader).state,
    ).toBe('loading')
    expect(
      describeHilosLegalTermsReader(termsSection, undefined, reader),
    ).toMatchObject({ state: 'loading', line: 'Loading the terms…' })
  })

  it('says so when no Terms are published', () => {
    expect(describeHilosLegalTermsReader(null, null, reader)).toMatchObject({
      state: 'unpublished',
      line: 'This project has not published its terms.',
      canAccept: false,
      canCompare: false,
    })
  })

  it('tells a guest since when the revision is in force and asks nothing', () => {
    expect(
      describeHilosLegalTermsReader(termsSection, undefined, {
        ...reader,
        person: null,
      }),
    ).toMatchObject({
      state: 'guest',
      line: 'Revision in force since 1 October 2026. Without an account there is nothing to accept.',
      canAccept: false,
    })
  })

  it('asks nothing of a person with no record', () => {
    expect(
      describeHilosLegalTermsReader(
        termsSection,
        termsAgreement('none', null),
        reader,
      ),
    ).toMatchObject({
      state: 'unrecorded',
      line: 'The revision of 1 October 2026 is in force. No acceptance of yours is on record.',
      canAccept: false,
      canCompare: false,
    })
  })

  it('confirms a person holding the revision in force', () => {
    expect(
      describeHilosLegalTermsReader(
        termsSection,
        termsAgreement('covered', substantial),
        reader,
      ),
    ).toMatchObject({
      state: 'covered',
      line: 'You accepted the revision of 1 October 2026 — it is the one in force.',
      canAccept: false,
    })
  })

  it('offers the comparison but no acceptance after wording-only changes', () => {
    expect(
      describeHilosLegalTermsReader(
        termsSectionBehind,
        termsAgreement('covered', first),
        reader,
      ),
    ).toMatchObject({
      state: 'reworded',
      line: 'You accepted the revision of 17 September 2026. The one in force since 1 October 2026 changes only the wording — nothing to accept.',
      canAccept: false,
      canCompare: true,
    })
  })

  it('asks for a decision inside the window, with the days left', () => {
    expect(
      describeHilosLegalTermsReader(
        termsSectionBehind,
        termsAgreement('window', first, '2026-11-10'),
        reader,
      ),
    ).toMatchObject({
      state: 'due',
      line: 'The terms changed on 1 October 2026. You accepted the revision of 17 September 2026.',
      plate: {
        tone: 'warning',
        text: '9 days left',
        detail: 'until 10 November 2026',
      },
      canAccept: true,
      canCompare: true,
      onlyPerson: null,
    })
  })

  it('names the freeze on the plate once the deadline passed', () => {
    const due = termsAgreement('lapsed', first, '2026-10-01')
    expect(
      describeHilosLegalTermsReader(termsSectionBehind, due, reader).plate
        ?.text,
    ).toBe('deadline passed 1 October 2026')
    expect(
      describeHilosLegalTermsReader(termsSectionBehind, due, {
        ...reader,
        frozen: true,
      }),
    ).toMatchObject({
      state: 'due',
      plate: {
        tone: 'info',
        text: 'account frozen · deadline passed 1 October 2026',
      },
      canAccept: true,
    })
  })

  it('shows the state under a takeover but offers no acceptance', () => {
    expect(
      describeHilosLegalTermsReader(
        termsSectionBehind,
        termsAgreement('window', first, '2026-11-10'),
        { ...reader, person: { name: 'Bob', impersonated: true } },
      ),
    ).toMatchObject({
      state: 'due',
      canAccept: false,
      canCompare: true,
      onlyPerson: 'Only Bob can accept the terms.',
    })
  })
})

describe('the Terms page store', () => {
  it('asks the page again when a group frame moves the Terms held, and keeps the pair shown', () => {
    const world = termsWorld(
      termsAgreement('covered', substantial),
      termsSection,
    )
    expect(world.store.terms.get()).toEqual(termsSection)
    const before = world.store.agreement.get()
    world.emit(termsAgreements(termsAgreement('lapsed', current, '2026-10-01')))
    expect(resubscribes(world.frames)).toEqual([
      { type: 'page_subscribe', page: 'hilos_terms', params: {} },
    ])
    expect(world.store.agreement.get()).toBe(before)
    expect(world.store.terms.get()).toEqual(termsSection)
    world.stop()
  })

  it('takes a frame that moved only the privacy policy without asking again', () => {
    const terms = termsAgreement('covered', substantial)
    const world = termsWorld(terms, termsSection)
    world.emit({
      documents: [
        terms,
        {
          ...terms,
          document: 'privacy',
          standing: 'window',
          deadline: '2026-11-10',
        },
      ],
    })
    expect(resubscribes(world.frames)).toEqual([])
    expect(world.store.agreement.get()).toEqual(terms)
    world.stop()
  })

  it('asks again and waits when another person reads in the tab', () => {
    const world = termsWorld(
      termsAgreement('covered', substantial),
      termsSection,
    )
    world.context.scopes.session.data.set('currentUser', {
      type: 'user',
      id: 2,
    })
    expect(resubscribes(world.frames)).toHaveLength(1)
    expect(world.store.agreement.get()).toBeUndefined()
    expect(
      describeHilosLegalTermsReader(
        world.store.terms.get(),
        world.store.agreement.get(),
        { person: BOB, frozen: false, now: NOVEMBER_FIRST },
      ).state,
    ).toBe('loading')
    world.page.data.set(
      'legalAgreements',
      termsAgreements(termsAgreement('none', null)),
    )
    expect(world.store.agreement.get()?.standing).toBe('none')
    world.stop()
  })

  it('accepts the Terms alone and stays busy until the page answers', async () => {
    const world = termsWorld()
    const accepted = world.store.accept()
    expect(world.requests).toHaveLength(1)
    expect(world.requests[0]).toMatchObject({
      name: 'hilos_legal_accept',
      payload: { acceptedRevisions: { terms: substantial.revisionId } },
    })
    expect(world.store.accepting.get()).toBe(true)
    expect(await world.store.accept()).toBe(false)
    world.requests[0]!.resolve({})
    expect(await accepted).toBe(true)
    expect(resubscribes(world.frames)).toHaveLength(1)
    expect(world.store.accepting.get()).toBe(true)
    world.page.data.set(
      'legalAgreements',
      termsAgreements(termsAgreement('covered', substantial)),
    )
    expect(world.store.accepting.get()).toBe(false)
    expect(world.store.agreement.get()?.standing).toBe('covered')
    world.stop()
  })

  it('keeps a refusal until the next press and asks the page again', async () => {
    const world = termsWorld()
    const refused = world.store.accept()
    world.requests[0]!.reject(
      new ActionError(
        'hilos_legal_accept',
        'fail',
        'The terms changed while you were reading them.',
      ),
    )
    expect(await refused).toBe(false)
    expect(world.store.refusal.get()).toBe(
      'The terms changed while you were reading them.',
    )
    expect(world.store.accepting.get()).toBe(false)
    expect(resubscribes(world.frames)).toHaveLength(1)
    void world.store.accept()
    expect(world.store.refusal.get()).toBeNull()
    world.stop()
  })

  it('accepts nothing before the page answered, and asks nothing off the page', async () => {
    const { actions, requests } = pendingActions()
    const world = legalContext(actions, { page: 'hilos_about', data: {} })
    const store = createHilosLegalTermsStore(world.context)
    const stop = store.start()
    expect(store.terms.get()).toBeUndefined()
    expect(await store.accept()).toBe(false)
    expect(requests).toEqual([])
    world.context.scopes.session.data.set('currentUser', {
      type: 'user',
      id: 2,
    })
    expect(resubscribes(world.frames)).toEqual([])
    stop()
  })

  it('reads an older revision with the page own action', () => {
    const { actions, requests } = pendingActions()
    const reader = createHilosLegalRevisionReader(
      legalContext(actions).context,
      {
        textAction: TERMS_REVISION_TEXT_ACTION,
      },
    )
    void reader.open('terms', first.revisionId)
    expect(requests[0]).toMatchObject({
      name: 'hilos_terms_revision_text',
      payload: { document: 'terms', revisionId: first.revisionId },
    })
    reader.close()
  })
})
