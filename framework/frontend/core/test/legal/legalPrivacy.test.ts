import { describe, expect, it } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import {
  type HilosLegalAgreement,
  type HilosLegalContext,
} from '../../src/legal/legalAgreements.js'
import {
  createHilosLegalPrivacyStore,
  describeHilosLegalPrivacyReader,
  legalPrivacySchema,
  legalPrivacySectionSchema,
  PRIVACY_REVISION_TEXT_ACTION,
} from '../../src/legal/legalPrivacy.js'
import {
  type HilosLegalPublicDocumentStore,
  legalPublicDocumentSchema,
  legalPublicDocumentSectionSchema,
} from '../../src/legal/legalPublicDocument.js'
import { createHilosLegalRevisionReader } from '../../src/legal/legalRevisions.js'
import {
  createHilosLegalTermsStore,
  legalTermsSchema,
} from '../../src/legal/legalTerms.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { bindPageScope } from '../../src/subscription/bindPageScope.js'
import {
  first,
  legalContext,
  privacyAgreement,
  privacyCurrent,
  privacySection,
  privacySectionBehind,
  substantial,
  termsAgreement,
  termsSection,
  termsSectionBehind,
} from './fixtures.js'

const READER = {
  person: { name: 'Bob', impersonated: false },
  frozen: false,
  now: Date.UTC(2026, 10, 1),
}

describe('the public Privacy schema and reader', () => {
  it('uses the shared schema for null, current, history and comparisons', () => {
    expect(legalPrivacySchema).toBe(legalPublicDocumentSchema)
    expect(legalTermsSchema).toBe(legalPublicDocumentSchema)
    expect(legalPrivacySectionSchema).toBe(legalPublicDocumentSectionSchema)
    expect(legalPrivacySectionSchema.parse(null)).toBeNull()
    expect(legalPrivacySchema.parse(privacySection)).toEqual(privacySection)
    expect(legalPrivacySchema.parse(privacySectionBehind)).toEqual(
      privacySectionBehind,
    )
    expect(
      legalPrivacySchema
        .parse(privacySection)
        .revisions.map((revision) => revision.revisionId),
    ).toEqual([first.revisionId, privacyCurrent.revisionId])
    const changes = ['changed', 'added', 'removed'].map((kind) => ({
      ...privacySectionBehind.changes!.changes[0],
      kind,
      before:
        kind === 'added'
          ? null
          : privacySectionBehind.changes!.changes[0]!.before,
      after:
        kind === 'removed'
          ? null
          : privacySectionBehind.changes!.changes[0]!.after,
    }))
    expect(
      legalPrivacySchema.parse({
        ...privacySectionBehind,
        changes: { ...privacySectionBehind.changes, changes },
      }).changes?.changes,
    ).toEqual(changes)
  })

  it.each([
    { ...privacySection, current: null },
    { ...privacySection, changes: undefined },
    {
      ...privacySection,
      clauses: [{ ...privacySection.clauses[0], direction: 'unknown' }],
    },
    {
      ...privacySection,
      revisions: [{ ...privacySection.revisions[0], previousSetVersion: '1' }],
    },
    {
      ...privacySectionBehind,
      changes: { ...privacySectionBehind.changes, fromRevisionId: null },
    },
  ])('refuses a malformed public section %#', (raw) => {
    expect(legalPrivacySectionSchema.safeParse(raw).success).toBe(false)
  })

  it('uses the policy loading and unpublished words, without waiting for a personal section when unpublished', () => {
    expect(
      describeHilosLegalPrivacyReader(undefined, undefined, READER),
    ).toMatchObject({
      state: 'loading',
      line: 'Loading the privacy policy…',
      canAccept: false,
    })
    expect(
      describeHilosLegalPrivacyReader(privacySection, undefined, READER).state,
    ).toBe('loading')
    expect(
      describeHilosLegalPrivacyReader(null, undefined, READER),
    ).toMatchObject({
      state: 'unpublished',
      line: 'This project has not published its privacy policy.',
      canAccept: false,
      canCompare: false,
    })
  })

  it('draws guest, unrecorded and covered states with no acceptance', () => {
    expect(
      describeHilosLegalPrivacyReader(privacySection, undefined, {
        ...READER,
        person: null,
      }),
    ).toMatchObject({
      state: 'guest',
      line: 'Revision in force since 14 November 2026. Without an account there is nothing to accept.',
      canAccept: false,
    })
    expect(
      describeHilosLegalPrivacyReader(
        privacySection,
        { ...privacyAgreement, held: null, standing: 'none' },
        READER,
      ),
    ).toMatchObject({
      state: 'unrecorded',
      line: 'The revision of 5 October 2026 is in force. No acceptance of yours is on record.',
      canAccept: false,
      canCompare: false,
    })
    expect(
      describeHilosLegalPrivacyReader(
        privacySection,
        { ...privacyAgreement, held: privacyCurrent, standing: 'covered' },
        READER,
      ),
    ).toMatchObject({
      state: 'covered',
      line: 'You accepted the revision of 5 October 2026 — it is the one in force.',
      canAccept: false,
      canCompare: false,
    })
  })

  it('allows a comparison after editorial changes without asking for acceptance', () => {
    expect(
      describeHilosLegalPrivacyReader(
        privacySectionBehind,
        { ...privacyAgreement, standing: 'covered' },
        READER,
      ),
    ).toMatchObject({
      state: 'reworded',
      line: 'You accepted the revision of 17 September 2026. The one in force since 14 November 2026 changes only the wording — nothing to accept.',
      canAccept: false,
      canCompare: true,
    })
  })

  it('uses the shared deadline plate for the window, lapse and freeze', () => {
    expect(
      describeHilosLegalPrivacyReader(
        privacySectionBehind,
        privacyAgreement,
        READER,
      ),
    ).toMatchObject({
      state: 'due',
      line: 'The privacy policy changed on 5 October 2026. You accepted the revision of 17 September 2026.',
      plate: {
        tone: 'warning',
        text: '13 days left',
        detail: 'until 14 November 2026',
      },
      canAccept: true,
      canCompare: true,
    })
    const lapsed = { ...privacyAgreement, standing: 'lapsed' as const }
    const now = Date.UTC(2026, 10, 15)
    expect(
      describeHilosLegalPrivacyReader(privacySectionBehind, lapsed, {
        ...READER,
        now,
      }).plate,
    ).toMatchObject({ text: 'deadline passed 14 November 2026' })
    expect(
      describeHilosLegalPrivacyReader(privacySectionBehind, lapsed, {
        ...READER,
        now,
        frozen: true,
      }).plate,
    ).toMatchObject({
      tone: 'info',
      text: 'account frozen · deadline passed 14 November 2026',
    })
  })

  it('keeps the comparison under impersonation and names the person who may accept', () => {
    expect(
      describeHilosLegalPrivacyReader(privacySectionBehind, privacyAgreement, {
        ...READER,
        person: { name: 'Bob', impersonated: true },
      }),
    ).toMatchObject({
      state: 'due',
      canAccept: false,
      canCompare: true,
      onlyPerson: 'Only Bob can accept the privacy policy.',
    })
  })

  it('reads an old policy through the public page action with the existing reply schema', () => {
    const world = publicWorld(PRIVACY)
    const reader = createHilosLegalRevisionReader(world.context, {
      textAction: PRIVACY_REVISION_TEXT_ACTION,
    })
    void reader.open('privacy', first.revisionId)
    expect(world.requests[0]).toMatchObject({
      name: 'hilos_privacy_revision_text',
      payload: { document: 'privacy', revisionId: first.revisionId },
    })
    reader.close()
    world.stop()
  })
})

/** The two thin public APIs exercised against the same lifecycle regressions. */
const PRIVACY = {
  document: 'privacy',
  page: 'hilos_privacy',
  section: 'legalPrivacy',
  current: privacyCurrent,
  sectionData: privacySection,
  behind: privacySectionBehind,
  agreement: privacyAgreement,
  create(context: HilosLegalContext) {
    const { privacy, ...store } = createHilosLegalPrivacyStore(context)
    return { ...store, document: privacy }
  },
} as const
const TERMS = {
  document: 'terms',
  page: 'hilos_terms',
  section: 'legalTerms',
  current: substantial,
  sectionData: termsSection,
  behind: termsSectionBehind,
  agreement: termsAgreement('window', first, '2026-11-10'),
  create(context: HilosLegalContext) {
    const { terms, ...store } = createHilosLegalTermsStore(context)
    return { ...store, document: terms }
  },
} as const

type PublicApi = typeof TERMS | typeof PRIVACY

/** Build a real page scope with controllable tracked replies and complete answers. */
function publicWorld(api: PublicApi, start = true) {
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
  const world = legalContext(actions, {
    page: api.page,
    data: {
      [api.section]: api.behind,
      legalAgreements: { documents: [api.agreement] },
    },
  })
  world.context.scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  const store: Omit<HilosLegalPublicDocumentStore, 'copy'> = api.create(
    world.context,
  )
  const stop = start ? store.start() : () => {}
  const covered: HilosLegalAgreement = {
    ...api.agreement,
    held: api.current,
    standing: 'covered',
    deadline: null,
  }
  return {
    ...world,
    store,
    stop,
    requests,
    covered,
    answerCovered() {
      world.answer({
        [api.section]: api.sectionData,
        legalAgreements: { documents: [covered] },
      })
    },
  }
}

describe.each([PRIVACY, TERMS])('$document public store lifecycle', (api) => {
  it('reads complete answers after the existing page binder and drops replies to a page left', () => {
    const world = publicWorld(api, false)
    const pages = bindPageScope(world.context.connection, world.context.scopes)
    pages.subscribe(api.page)
    world.project('page_response', {
      page: api.page,
      payload: {
        data: {
          [api.section]: api.behind,
          legalAgreements: { documents: [api.agreement] },
        },
      },
    })
    const stop = world.store.start()
    expect(world.store.document.get()).toEqual(api.behind)
    const pairs: unknown[] = []
    const off = subscribeSignal(world.store.document, () =>
      pairs.push(world.store.agreement.get()),
    )
    world.project('page_response', {
      page: api.page,
      payload: {
        data: {
          [api.section]: api.sectionData,
          legalAgreements: { documents: [world.covered] },
        },
      },
    })
    expect(pairs).toEqual([world.covered])
    pages.subscribe('hilos_about')
    world.project('page_response', {
      page: api.page,
      payload: { data: { [api.section]: api.behind } },
    })
    expect(world.store.document.get()).toBeUndefined()
    expect(
      world.context.scopes.page()?.data.signal(api.section).get(),
    ).toBeUndefined()
    off()
    stop()
  })

  it('stays inert for SSR and owns listeners only between start and cleanup', async () => {
    const world = publicWorld(api, false)
    expect(world.listenerCount()).toBe(0)
    expect(world.store.document.get()).toBeUndefined()
    expect(await world.store.accept()).toBe(false)
    expect(world.frames).toEqual([])
    const stop = world.store.start()
    expect(world.listenerCount()).toBe(1)
    expect(world.store.document.get()).toEqual(api.behind)
    stop()
    stop()
    expect(world.listenerCount()).toBe(0)
    expect(world.store.document.get()).toBeUndefined()
    expect(world.store.agreement.get()).toBeUndefined()
  })

  it('keeps text and comparison together until a whole refresh, and ignores duplicate group hints', () => {
    const world = publicWorld(api)
    const before = world.store.agreement.get()
    world.emit({ documents: [world.covered] })
    world.emit({ documents: [world.covered] })
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: api.page, params: {} },
    ])
    expect(world.store.agreement.get()).toBe(before)
    expect(world.store.document.get()).toEqual(api.behind)
    // The normalizer writes slots separately; neither half is published before
    // the complete page_response announces that the whole answer has landed.
    world.page.data.set('legalAgreements', { documents: [world.covered] })
    expect(world.store.agreement.get()).toBe(before)
    expect(world.store.document.get()).toEqual(api.behind)
    const seen: unknown[] = []
    const off = subscribeSignal(world.store.agreement, () =>
      seen.push(world.store.document.get()),
    )
    world.answerCovered()
    expect(seen).toEqual([api.sectionData])
    expect(world.store.agreement.get()).toEqual(world.covered)
    off()
    world.stop()
  })

  it('refreshes when its current changes even when its held revision does not', () => {
    const world = publicWorld(api)
    const current = {
      ...api.current,
      revisionId: '2026-10-20',
      publishedOn: '2026-10-20',
    }
    const next = { ...api.agreement, current }
    world.emit({ documents: [next] })
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: api.page, params: {} },
    ])
    expect(world.store.document.get()?.current).toEqual(api.current)
    world.answer({
      [api.section]: {
        ...api.behind,
        current,
        changes: { ...api.behind.changes, toRevisionId: current.revisionId },
      },
      legalAgreements: { documents: [next] },
    })
    expect(world.store.document.get()?.current).toEqual(current)
    expect(world.store.agreement.get()).toEqual(next)
    world.stop()
  })

  it('uses lightweight standing changes directly when its current and held stay put', () => {
    const world = publicWorld(api)
    const next = {
      ...api.agreement,
      standing: 'lapsed',
      deadline: '2026-10-01',
    }
    world.emit({ documents: [next] })
    expect(world.store.agreement.get()).toEqual(next)
    expect(world.frames).toEqual([])
    world.stop()
  })

  it('retains a newer group revision that overtakes an already requested answer', () => {
    const world = publicWorld(api)
    const earlier = {
      ...api.agreement,
      current: { ...api.current, revisionId: '2026-10-20' },
    }
    const latest = {
      ...api.agreement,
      current: { ...api.current, revisionId: '2026-10-22' },
    }
    world.emit({ documents: [earlier] })
    world.emit({ documents: [latest] })
    expect(world.frames).toHaveLength(1)
    world.answer({
      [api.section]: { ...api.behind, current: earlier.current },
      legalAgreements: { documents: [earlier] },
    })
    expect(world.frames).toHaveLength(2)
    world.answer({
      [api.section]: { ...api.behind, current: latest.current },
      legalAgreements: { documents: [latest] },
    })
    expect(world.store.agreement.get()).toEqual(latest)
    expect(world.frames).toHaveLength(2)
    world.stop()
  })

  it('accepts the complete page verdict when it is already newer than the requesting hint', () => {
    const world = publicWorld(api)
    const hint = {
      ...api.agreement,
      current: { ...api.current, revisionId: '2026-10-20' },
    }
    const latest = {
      ...api.agreement,
      current: { ...api.current, revisionId: '2026-10-22' },
    }
    world.emit({ documents: [hint] })
    world.answer({
      [api.section]: { ...api.behind, current: latest.current },
      legalAgreements: { documents: [latest] },
    })
    expect(world.store.agreement.get()).toEqual(latest)
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: api.page, params: {} },
    ])
    world.stop()
  })

  it('accepts only its shown document once and waits for authoritative coverage', async () => {
    const world = publicWorld(api)
    const accepted = world.store.accept()
    expect(world.requests).toHaveLength(1)
    expect(world.requests[0]).toMatchObject({
      name: 'hilos_legal_accept',
      payload: {
        acceptedRevisions: { [api.document]: api.current.revisionId },
      },
    })
    expect(await world.store.accept()).toBe(false)
    world.emit({ documents: [world.covered] })
    expect(world.frames).toEqual([])
    expect(world.store.agreement.get()).toEqual(api.agreement)
    // A page answer arriving before the tracked ACK must not unlock a second press.
    world.answer({
      [api.section]: api.behind,
      legalAgreements: { documents: [api.agreement] },
    })
    expect(world.store.accepting.get()).toBe(true)
    world.requests[0]!.resolve({})
    expect(await accepted).toBe(true)
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: api.page, params: {} },
    ])
    expect(world.store.accepting.get()).toBe(true)
    expect(world.store.agreement.get()?.standing).toBe('window')
    world.answerCovered()
    expect(world.store.accepting.get()).toBe(false)
    expect(world.store.agreement.get()?.standing).toBe('covered')
    world.stop()
  })

  it('preserves CONSENT_REVISED words and refreshes the complete revision before another press', async () => {
    const world = publicWorld(api)
    const accepted = world.store.accept()
    const message =
      'The documents changed while you were reading them. Review the new revision.'
    world.requests[0]!.reject(
      new ActionError(
        'hilos_legal_accept',
        'fail',
        message,
        undefined,
        undefined,
        'consent_revised',
      ),
    )
    expect(await accepted).toBe(false)
    expect(world.store.refusal.get()).toBe(message)
    expect(world.store.accepting.get()).toBe(false)
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: api.page, params: {} },
    ])
    const current = { ...api.current, revisionId: '2026-10-22' }
    world.answer({
      [api.section]: { ...api.behind, current },
      legalAgreements: { documents: [{ ...api.agreement, current }] },
    })
    expect(world.store.refusal.get()).toBe(message)
    const again = world.store.accept()
    expect(world.store.refusal.get()).toBeNull()
    expect(world.requests[1]!.payload).toEqual({
      acceptedRevisions: { [api.document]: current.revisionId },
    })
    world.requests[1]!.resolve({})
    expect(await again).toBe(true)
    world.stop()
  })

  it.each(['ack', 'refusal'] as const)(
    'discards a late $document %s after identity change',
    async (outcome) => {
      const world = publicWorld(api)
      const old = world.store.accept()
      world.context.scopes.session.data.set('currentUser', {
        type: 'user',
        id: 2,
      })
      expect(world.store.agreement.get()).toBeUndefined()
      expect(world.store.document.get()?.changes).toBeNull()
      expect(world.store.accepting.get()).toBe(false)
      expect(world.store.refusal.get()).toBeNull()
      world.answer({
        [api.section]: api.behind,
        legalAgreements: { documents: [api.agreement] },
      })
      const next = world.store.accept()
      if (outcome === 'ack') world.requests[0]!.resolve({})
      else
        world.requests[0]!.reject(
          new ActionError('hilos_legal_accept', 'fail', 'Old person refusal'),
        )
      expect(await old).toBe(false)
      expect(world.store.accepting.get()).toBe(true)
      expect(world.store.refusal.get()).toBeNull()
      expect(world.frames).toHaveLength(1)
      world.requests[1]!.resolve({})
      expect(await next).toBe(true)
      expect(world.frames).toHaveLength(2)
      world.stop()
    },
  )

  it.each(['ack', 'refusal'] as const)(
    'discards a late %s and a stale cleanup after restart',
    async (outcome) => {
      const world = publicWorld(api)
      const old = world.store.accept()
      world.stop()
      world.answerCovered()
      expect(world.store.document.get()).toBeUndefined()
      const stop = world.store.start()
      expect(world.listenerCount()).toBe(1)
      const next = world.store.accept()
      world.stop()
      if (outcome === 'ack') world.requests[0]!.resolve({})
      else
        world.requests[0]!.reject(
          new ActionError('hilos_legal_accept', 'fail', 'Old run refusal'),
        )
      expect(await old).toBe(false)
      expect(world.store.accepting.get()).toBe(true)
      expect(world.store.refusal.get()).toBeNull()
      expect(world.listenerCount()).toBe(1)
      expect(world.frames).toEqual([])
      world.requests[1]!.resolve({})
      expect(await next).toBe(true)
      stop()
      expect(world.listenerCount()).toBe(0)
    },
  )

  it('clears personal comparison immediately on sign-out, including after a refusal', async () => {
    const world = publicWorld(api)
    const accepted = world.store.accept()
    world.requests[0]!.reject(
      new ActionError('hilos_legal_accept', 'fail', 'Refused'),
    )
    await accepted
    world.context.scopes.session.data.set('currentUser', null)
    expect(world.store.agreement.get()).toBeNull()
    expect(world.store.document.get()?.changes).toBeNull()
    expect(world.store.refusal.get()).toBeNull()
    expect(world.store.accepting.get()).toBe(false)
    expect(await world.store.accept()).toBe(false)
    world.answer({ [api.section]: api.sectionData })
    expect(world.store.agreement.get()).toBeNull()
    world.stop()
  })

  it('ends the run on navigation and ignores old page replies and scope writes', async () => {
    const world = publicWorld(api)
    const old = world.store.accept()
    world.context.scopes.openPage('hilos_about')
    world.page.data.set(api.section, api.sectionData)
    world.answerCovered()
    expect(world.store.document.get()).toBeUndefined()
    expect(world.store.agreement.get()).toBeUndefined()
    expect(world.listenerCount()).toBe(0)
    world.requests[0]!.resolve({})
    expect(await old).toBe(false)
    expect(world.frames).toEqual([])
    expect(await world.store.accept()).toBe(false)
    world.stop()
  })

  it('ignores another page response and treats unpublished as a complete answer without agreements', () => {
    const world = publicWorld(api)
    world.answer({ [api.section]: null }, 'hilos_about')
    expect(world.store.document.get()).toEqual(api.behind)
    world.answer({ [api.section]: null })
    expect(world.store.document.get()).toBeNull()
    expect(world.store.agreement.get()).toBeNull()
    world.stop()
  })
})

describe('independent document changes', () => {
  it('rereads a new Privacy current while the Terms current and held stay unchanged', () => {
    const world = publicWorld(PRIVACY)
    const terms = termsAgreement('covered', substantial)
    const current = { ...privacyCurrent, revisionId: '2026-10-22' }
    const privacy = { ...privacyAgreement, current }
    world.emit({ documents: [terms, privacy] })
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: 'hilos_privacy', params: {} },
    ])
    expect(world.store.document.get()?.current).toEqual(privacyCurrent)
    world.answer({
      legalPrivacy: {
        ...privacySectionBehind,
        current,
        changes: {
          ...privacySectionBehind.changes,
          toRevisionId: current.revisionId,
        },
      },
      legalAgreements: { documents: [terms, privacy] },
    })
    expect(world.store.document.get()?.current).toEqual(current)
    expect(world.store.agreement.get()).toEqual(privacy)
    world.stop()
  })

  it('refreshes Privacy when its held changes while Terms remains the same', () => {
    const world = publicWorld(PRIVACY)
    const terms = termsAgreement('covered', substantial)
    world.emit({ documents: [terms, world.covered] })
    expect(world.frames).toEqual([
      { type: 'page_subscribe', page: 'hilos_privacy', params: {} },
    ])
    expect(world.store.agreement.get()).toEqual(privacyAgreement)
    world.answer({
      legalPrivacy: privacySection,
      legalAgreements: { documents: [terms, world.covered] },
    })
    expect(world.store.document.get()).toEqual(privacySection)
    expect(world.store.agreement.get()).toEqual(world.covered)
    world.stop()
  })

  it('does not reread Privacy after a terms-only frame', () => {
    const world = publicWorld(PRIVACY)
    world.emit({
      documents: [
        termsAgreement('lapsed', first, '2026-10-01'),
        privacyAgreement,
      ],
    })
    expect(world.frames).toEqual([])
    expect(world.store.document.get()).toEqual(privacySectionBehind)
    expect(world.store.agreement.get()).toEqual(privacyAgreement)
    world.stop()
  })
})
