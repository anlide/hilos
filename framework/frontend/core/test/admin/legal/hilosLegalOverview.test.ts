import { describe, expect, it } from 'vitest'
import {
  LEGAL_OVERVIEW_SECTION,
  createHilosLegalOverview,
  legalOverviewSchema,
} from '../../../src/admin/legal/hilosLegalOverview.js'
import type { HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const overview = {
  documentCount: 2,
  documents: [
    {
      document: 'terms',
      documentLabel: 'Terms of use',
      window: 3,
      lapsed: 1,
    },
    {
      document: 'privacy',
      documentLabel: 'Privacy policy',
      window: 0,
      lapsed: 0,
    },
  ],
  newerSetCount: 1,
}

const emptyOverview = {
  documentCount: 0,
  documents: [],
  newerSetCount: 0,
}

describe('legalOverviewSchema', () => {
  it('accepts the complete server overview and a valid empty summary', () => {
    expect(legalOverviewSchema.parse(overview)).toEqual(overview)
    expect(legalOverviewSchema.parse(emptyOverview)).toEqual(emptyOverview)
  })

  it.each([
    ['a fractional document count', { ...overview, documentCount: 1.5 }],
    ['a fractional newer-set count', { ...overview, newerSetCount: 0.5 }],
    ['a negative document count', { ...overview, documentCount: -1 }],
    [
      'a negative window count',
      {
        ...overview,
        documents: [
          { ...overview.documents[0]!, window: -1 },
          overview.documents[1]!,
        ],
      },
    ],
    [
      'a fractional lapsed count',
      {
        ...overview,
        documents: [
          { ...overview.documents[0]!, lapsed: 0.5 },
          overview.documents[1]!,
        ],
      },
    ],
    [
      'a document count that does not match the list',
      {
        ...overview,
        documentCount: 1,
      },
    ],
    [
      'duplicate document keys',
      {
        ...overview,
        documents: [overview.documents[0]!, { ...overview.documents[0]! }],
      },
    ],
    ['more newer sets than documents', { ...overview, newerSetCount: 3 }],
    [
      'an incomplete document row',
      {
        documentCount: 1,
        documents: [{ document: 'terms', window: 0, lapsed: 0 }],
        newerSetCount: 0,
      },
    ],
    [
      'an extra document field',
      {
        ...overview,
        documents: [
          { ...overview.documents[0]!, extra: true },
          overview.documents[1]!,
        ],
      },
    ],
    ['an extra overview field', { ...overview, extra: true }],
    ['null', null],
    ['missing data', undefined],
  ])('rejects %s', (_description, data) => {
    expect(legalOverviewSchema.safeParse(data).success).toBe(false)
  })
})

describe('createHilosLegalOverview', () => {
  it('reads the server summary and replaces all document rows on update', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.LEGAL)
    const signal = createHilosLegalOverview({ scopes } as HilosLegalContext)

    expect(signal.get()).toBeNull()
    page.data.set(LEGAL_OVERVIEW_SECTION, overview)
    expect(signal.get()).toEqual(overview)

    const replacement = {
      documentCount: 1,
      documents: [
        {
          document: 'privacy',
          documentLabel: 'Privacy policy',
          window: 2,
          lapsed: 0,
        },
      ],
      newerSetCount: 0,
    }
    page.data.set(LEGAL_OVERVIEW_SECTION, replacement)
    expect(signal.get()).toEqual(replacement)
  })

  it('keeps valid zero counts as a real empty summary', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.LEGAL)
    const signal = createHilosLegalOverview({ scopes } as HilosLegalContext)

    page.data.set(LEGAL_OVERVIEW_SECTION, emptyOverview)

    expect(signal.get()).toEqual(emptyOverview)
  })

  it('clears on malformed, missing, null, and replaced page scopes', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.LEGAL)
    const signal = createHilosLegalOverview({ scopes } as HilosLegalContext)

    expect(signal.get()).toBeNull()
    page.data.set(LEGAL_OVERVIEW_SECTION, overview)
    expect(signal.get()).toEqual(overview)

    page.data.set(LEGAL_OVERVIEW_SECTION, {
      ...overview,
      documents: [
        {
          document: 'terms',
          documentLabel: 'Terms of use',
          window: 1,
        },
      ],
    })
    expect(signal.get()).toBeNull()

    page.data.set(LEGAL_OVERVIEW_SECTION, undefined)
    expect(signal.get()).toBeNull()
    page.data.set(LEGAL_OVERVIEW_SECTION, null)
    expect(signal.get()).toBeNull()

    page.data.set(LEGAL_OVERVIEW_SECTION, overview)
    expect(signal.get()).toEqual(overview)

    scopes.openPage('anotherPage')
    expect(signal.get()).toBeNull()
    scopes.dropPage()
    expect(signal.get()).toBeNull()
    scopes.openPage(HilosPages.LEGAL)
    expect(signal.get()).toBeNull()
  })
})
