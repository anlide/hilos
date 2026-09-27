import { describe, expect, it } from 'vitest'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import {
  createHilosLegalAgreementsStore,
  describeHilosLegalAgreement,
  describeHilosLegalAgreements,
  formatHilosLegalDate,
  legalAgreementsStateSchema,
  legalChangeSchema,
} from '../../src/legal/legalAgreements.js'
import {
  agreement,
  change,
  clause,
  first,
  legalContext,
  state,
  texts,
} from './fixtures.js'

describe('legal agreement contracts', () => {
  it('requires meaningful fields and known document and state vocabulary', () => {
    expect(legalAgreementsStateSchema.parse(state)).toEqual(state)
    expect(
      legalAgreementsStateSchema.safeParse({
        documents: [{ ...agreement, document: 'other' }],
      }).success,
    ).toBe(false)
    expect(
      legalAgreementsStateSchema.safeParse({
        documents: [{ ...agreement, standing: 'yes' }],
      }).success,
    ).toBe(false)
    expect(
      legalAgreementsStateSchema.safeParse({
        documents: [{ ...agreement, acceptedAt: undefined }],
      }).success,
    ).toBe(false)
    expect(
      legalChangeSchema.parse({ ...change, kind: 'removed', after: null })
        .after,
    ).toBeNull()
  })
  it('formats calendar dates without moving them across time zones', () => {
    expect(formatHilosLegalDate('2026-09-17')).toBe('17 September 2026')
    expect(formatHilosLegalDate('2026-01-01')).toBe('1 January 2026')
  })
  it('summarizes the worst document and the nearest outstanding date', () => {
    const none = {
      ...agreement,
      standing: 'none' as const,
      held: null,
      acceptedAt: null,
      accepted: [],
    }
    expect(describeHilosLegalAgreements({ documents: [] })).toBe(
      'This project publishes no legal documents',
    )
    expect(describeHilosLegalAgreements({ documents: [none] })).toBe(
      'Nothing accepted on record',
    )
    expect(describeHilosLegalAgreements(state)).toBe(
      'Terms and privacy accepted',
    )
    const window = {
      ...agreement,
      standing: 'window' as const,
      deadline: '2026-10-01',
    }
    expect(describeHilosLegalAgreements({ documents: [none, window] })).toBe(
      'Terms of use updated — until 1 October 2026',
    )
    expect(
      describeHilosLegalAgreements({
        documents: [
          window,
          { ...agreement, document: 'privacy', standing: 'lapsed' },
        ],
      }),
    ).toBe('Not accepted: Privacy policy')
    expect(
      describeHilosLegalAgreements({
        documents: [
          window,
          { ...window, document: 'privacy', deadline: '2026-09-30' },
        ],
      }),
    ).toContain('until 30 September 2026')
  })
  it('distinguishes absent acceptance, wording coverage, a window and an expired deadline', () => {
    expect(
      describeHilosLegalAgreement({
        ...agreement,
        standing: 'none',
        held: null,
      }).summary,
    ).toBe('No acceptance on record · revision of 27 September 2026 in force')
    expect(describeHilosLegalAgreement(agreement).notice).toContain(
      'wording only, your acceptance still covers it',
    )
    expect(
      describeHilosLegalAgreement({
        ...agreement,
        standing: 'window',
        deadline: '2026-10-01',
      }).notice,
    ).toContain('You have until 1 October 2026')
    expect(
      describeHilosLegalAgreement({
        ...agreement,
        standing: 'lapsed',
        deadline: '2026-10-01',
      }).notice,
    ).toContain('You have not accepted this revision')
  })
  it('takes the initial state and texts from the page, updates from a group, and releases both on stop', () => {
    const world = legalContext({} as ActionLifecycle)
    const store = createHilosLegalAgreementsStore(world.context)
    expect(store.state.get()).toBeNull()
    const stop = store.start()
    expect(store.state.get()).toEqual(state)
    expect(store.texts.get()).toEqual(texts)
    const expired = {
      documents: [{ ...agreement, standing: 'lapsed', deadline: '2026-09-01' }],
    }
    world.emit(expired)
    expect(store.state.get()).toEqual(expired)
    expect(world.frames).toEqual([])
    expect(store.texts.get()).toEqual(texts)
    stop()
    expect(world.listenerCount()).toBe(0)
    world.emit(state)
    expect(store.state.get()).toBeNull()
    expect(store.texts.get()).toBeNull()
  })
})

it('refreshes the matched agreement state and text when another tab accepts a different revision', () => {
  const world = legalContext({} as ActionLifecycle)
  const store = createHilosLegalAgreementsStore(world.context)
  const stop = store.start()
  const next = {
    documents: [
      {
        ...agreement,
        held: { ...first, revisionId: '2026-09-20', publishedOn: '2026-09-20' },
        acceptedAt: Date.UTC(2026, 8, 21),
        accepted: [
          ...agreement.accepted,
          { revisionId: '2026-09-20', acceptedAt: Date.UTC(2026, 8, 21) },
        ],
      },
    ],
  }
  world.emit(next)
  expect(world.frames).toEqual([
    { type: 'page_subscribe', page: 'hilos_profile_agreements', params: {} },
  ])
  expect(store.state.get()).toEqual(state)
  expect(store.texts.get()).toEqual(texts)
  const nextTexts = {
    documents: [
      {
        ...texts.documents[0]!,
        held: [{ ...clause, text: 'The newly accepted text' }],
      },
    ],
  }
  world.page.data.set('legalAgreements', next)
  world.page.data.set('legalAgreementTexts', nextTexts)
  expect(store.state.get()).toEqual(next)
  expect(store.texts.get()).toEqual(nextTexts)
  world.emit(next)
  expect(world.frames).toHaveLength(1)
  stop()
})

it('updates the lightweight profile and history state without asking for agreement texts', () => {
  for (const pageKey of ['hilos_profile', 'hilos_profile_agreements_history']) {
    const world = legalContext({} as ActionLifecycle)
    world.context.scopes.openPage(pageKey).data.set('legalAgreements', state)
    const store = createHilosLegalAgreementsStore(world.context)
    const stop = store.start()
    const next = { documents: [{ ...agreement, held: agreement.current }] }
    world.emit(next)
    expect(store.state.get()).toEqual(next)
    expect(world.frames).toEqual([])
    stop()
  }
})
