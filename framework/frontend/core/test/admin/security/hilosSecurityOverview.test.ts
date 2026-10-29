import { describe, expect, it } from 'vitest'
import {
  SECURITY_OVERVIEW_DATA,
  createHilosSecurityOverview,
  type HilosSecurityOverview,
} from '../../../src/admin/security/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const overview = {
  twoFactor: { enabled: true, required: 'admins' },
  stepUp: { enabledCount: 2, totalCount: 3 },
  oauth: {
    configuredCount: 1,
    totalCount: 3,
    unconfigured: [
      { providerKey: 'github', label: 'GitHub', missingFields: 1 },
      { providerKey: 'custom', label: 'Custom provider', missingFields: 2 },
    ],
  },
  signInMethods: {
    enabledCount: 3,
    totalCount: 4,
    unready: [{ methodKey: 'sms', label: 'Phone code' }],
  },
  impersonation: { allowed: true, scope: 'view' },
} satisfies HilosSecurityOverview

const zeroOverview = {
  twoFactor: { enabled: false, required: 'none' },
  stepUp: { enabledCount: 0, totalCount: 0 },
  oauth: { configuredCount: 0, totalCount: 0, unconfigured: [] },
  signInMethods: { enabledCount: 0, totalCount: 0, unready: [] },
  impersonation: { allowed: false, scope: 'act' },
} satisfies HilosSecurityOverview

describe('createHilosSecurityOverview', () => {
  it('reads complete and zero overviews and follows updates in the actual page scope', () => {
    const scopes = new ScopeManager()
    const signal = createHilosSecurityOverview({ scopes })
    expect(signal.get()).toBeUndefined()

    const page = scopes.openPage(HilosPages.SECURITY)
    expect(signal.get()).toBeUndefined()
    expect(SECURITY_OVERVIEW_DATA).toBe('securityOverview')
    page.data.set('securityOverview', overview)
    expect(signal.get()).toEqual(overview)

    page.data.set('securityOverview', zeroOverview)
    expect(signal.get()).toEqual(zeroOverview)

    const updated = {
      ...overview,
      twoFactor: { enabled: false, required: 'everyone' },
      stepUp: { enabledCount: 3, totalCount: 3 },
      oauth: { configuredCount: 3, totalCount: 3, unconfigured: [] },
      signInMethods: { enabledCount: 4, totalCount: 4, unready: [] },
      impersonation: { allowed: false, scope: 'act' },
    }
    page.data.set('securityOverview', updated)
    expect(signal.get()).toEqual(updated)
  })

  it('normalizes all hidden settings without hiding directory totals or OAuth data', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    page.data.set('securityOverview', {
      ...overview,
      twoFactor: { enabled: { _hidden: true }, required: { _hidden: true } },
      stepUp: { enabledCount: { _hidden: true }, totalCount: 0 },
      signInMethods: {
        enabledCount: { _hidden: true },
        totalCount: 0,
        unready: { _hidden: true },
      },
      impersonation: { allowed: { _hidden: true }, scope: { _hidden: true } },
    })

    const result = signal.get()!
    expect(result.twoFactor.enabled).toBe(HIDDEN_VALUE)
    expect(result.twoFactor.required).toBe(HIDDEN_VALUE)
    expect(result.stepUp.enabledCount).toBe(HIDDEN_VALUE)
    expect(result.signInMethods.enabledCount).toBe(HIDDEN_VALUE)
    expect(result.signInMethods.unready).toBe(HIDDEN_VALUE)
    expect(result.impersonation.allowed).toBe(HIDDEN_VALUE)
    expect(result.impersonation.scope).toBe(HIDDEN_VALUE)
    expect(result.stepUp.totalCount).toBe(0)
    expect(result.signInMethods.totalCount).toBe(0)
    expect(result.oauth).toEqual(overview.oauth)
  })

  it('preserves visible neighbors when settings are independently hidden', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    const partiallyHidden = {
      ...overview,
      twoFactor: { enabled: { _hidden: true }, required: 'everyone' },
      signInMethods: { ...overview.signInMethods, unready: { _hidden: true } },
      impersonation: { allowed: false, scope: { _hidden: true } },
    }
    page.data.set('securityOverview', partiallyHidden)
    expect(signal.get()).toEqual(partiallyHidden)
    expect(signal.get()?.twoFactor.enabled).toBe(HIDDEN_VALUE)
    expect(signal.get()?.signInMethods.unready).toBe(HIDDEN_VALUE)
    expect(signal.get()?.impersonation.scope).toBe(HIDDEN_VALUE)
  })

  it.each([
    ['a missing value', undefined],
    ['null', null],
    ['an array', []],
    ['a scalar', false],
    ['an extra overview field', { ...overview, extra: true }],
    ...Object.entries(overview).flatMap<[string, unknown]>(
      ([group, fields]) => [
        [
          `an extra ${group} field`,
          { ...overview, [group]: { ...fields, extra: true } },
        ],
        [`a missing ${group} group`, { ...overview, [group]: undefined }],
        [
          `a hidden ${group} group`,
          { ...overview, [group]: { _hidden: true } },
        ],
        ...Object.keys(fields).map<[string, unknown]>((field) => [
          `a missing ${group}.${field} field`,
          { ...overview, [group]: { ...fields, [field]: undefined } },
        ]),
      ],
    ),
    [
      'a non-boolean policy',
      { ...overview, twoFactor: { enabled: 1, required: 'none' } },
    ],
    [
      'an unknown requirement',
      { ...overview, twoFactor: { enabled: true, required: 'staff' } },
    ],
    [
      'an unknown scope',
      { ...overview, impersonation: { allowed: true, scope: 'write' } },
    ],
    [
      'a non-boolean allowance',
      { ...overview, impersonation: { allowed: 'false', scope: 'act' } },
    ],
    [
      'a malformed hidden mark',
      {
        ...overview,
        twoFactor: { enabled: { _hidden: false }, required: 'none' },
      },
    ],
    [
      'a hidden mark with extra data',
      {
        ...overview,
        impersonation: {
          allowed: { _hidden: true, value: false },
          scope: 'view',
        },
      },
    ],
    ...(['stepUp', 'signInMethods'] as const).map<[string, unknown]>(
      (group) => [
        `${group} enabled count above the total`,
        {
          ...overview,
          [group]: {
            ...overview[group],
            enabledCount: overview[group].totalCount + 1,
          },
        },
      ],
    ),
    [
      'configured count above the total',
      { ...overview, oauth: { ...overview.oauth, configuredCount: 4 } },
    ],
  ])('clears a previous overview on %s', (_description, data) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    page.data.set('securityOverview', overview)
    expect(signal.get()).toEqual(overview)

    page.data.set('securityOverview', data)
    expect(signal.get()).toBeUndefined()

    page.data.set('securityOverview', overview)
    expect(signal.get()).toEqual(overview)
  })

  it.each([
    ['stepUp', 'enabledCount'],
    ['stepUp', 'totalCount'],
    ['oauth', 'configuredCount'],
    ['oauth', 'totalCount'],
    ['signInMethods', 'enabledCount'],
    ['signInMethods', 'totalCount'],
  ] as const)('rejects invalid numbers in %s.%s', (group, field) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    for (const value of [-1, 0.5, Infinity, NaN, '1', null]) {
      page.data.set('securityOverview', {
        ...overview,
        [group]: { ...overview[group], [field]: value },
      })
      expect(signal.get(), String(value)).toBeUndefined()
    }
  })

  it.each([
    [
      'hidden configured count',
      { ...overview.oauth, configuredCount: { _hidden: true } },
    ],
    ['hidden total', { ...overview.oauth, totalCount: { _hidden: true } }],
    ['hidden warnings', { ...overview.oauth, unconfigured: { _hidden: true } }],
    ...[-1, 0, 0.5, 3, '1', null].map<[string, unknown]>((missingFields) => [
      `invalid missingFields ${String(missingFields)}`,
      {
        ...overview.oauth,
        unconfigured: [
          { providerKey: 'custom', label: 'Custom', missingFields },
        ],
      },
    ]),
    [
      'extra warning field',
      {
        ...overview.oauth,
        unconfigured: [
          {
            providerKey: 'custom',
            label: 'Custom',
            missingFields: 1,
            extra: true,
          },
        ],
      },
    ],
    [
      'missing warning label',
      {
        ...overview.oauth,
        unconfigured: [{ providerKey: 'custom', missingFields: 1 }],
      },
    ],
    [
      'non-string provider key',
      {
        ...overview.oauth,
        unconfigured: [{ providerKey: 1, label: 'Custom', missingFields: 1 }],
      },
    ],
  ])('rejects OAuth %s', (_description, oauth) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    page.data.set('securityOverview', { ...overview, oauth })
    expect(signal.get()).toBeUndefined()
  })

  it.each([
    ['a non-list', {}],
    ['a missing label', [{ methodKey: 'sms' }]],
    ['a missing method key', [{ label: 'Phone code' }]],
    ['a non-string method key', [{ methodKey: 1, label: 'Phone code' }]],
    ['a non-string label', [{ methodKey: 'sms', label: false }]],
    [
      'an extra field',
      [{ methodKey: 'sms', label: 'Phone code', extra: true }],
    ],
    ['a hidden list item', [{ _hidden: true }]],
  ])('rejects malformed sign-in warnings with %s', (_description, unready) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    page.data.set('securityOverview', {
      ...overview,
      signInMethods: { ...overview.signInMethods, unready },
    })
    expect(signal.get()).toBeUndefined()
  })

  it('clears on foreign pages, drop and reopen, even when another page has the same data key', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY)
    const signal = createHilosSecurityOverview({ scopes })
    page.data.set('securityOverview', overview)
    expect(signal.get()).toEqual(overview)

    const foreign = scopes.openPage(HilosPages.SECURITY_OAUTH)
    foreign.data.set('securityOverview', overview)
    expect(signal.get()).toBeUndefined()

    page.data.set('securityOverview', zeroOverview)
    expect(signal.get()).toBeUndefined()
    scopes.dropPage()
    expect(signal.get()).toBeUndefined()

    const reopened = scopes.openPage(HilosPages.SECURITY)
    expect(signal.get()).toBeUndefined()
    reopened.data.set('securityOverview', zeroOverview)
    expect(signal.get()).toEqual(zeroOverview)

    scopes.openPage(HilosPages.SECURITY)
    expect(signal.get()).toBeUndefined()
  })
})
