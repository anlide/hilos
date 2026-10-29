import { describe, expect, it } from 'vitest'
import {
  OAUTH_PROVIDER_HEAD_DATA,
  createHilosOAuthProviderHead,
  type HilosOAuthProviderHead,
} from '../../../src/admin/security/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const head = {
  providerKey: 'custom',
  label: 'Custom provider',
  configured: false,
  missingFields: 2,
} satisfies HilosOAuthProviderHead

describe('createHilosOAuthProviderHead', () => {
  it('reads the actual page scope and follows readiness and identity updates', () => {
    const scopes = new ScopeManager()
    const signal = createHilosOAuthProviderHead({ scopes })
    expect(signal.get()).toBeUndefined()
    const page = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
    expect(signal.get()).toBeUndefined()
    expect(OAUTH_PROVIDER_HEAD_DATA).toBe('oauthProviderHead')

    page.data.set('oauthProviderHead', head)
    expect(signal.get()).toEqual(head)
    const oneMissing = { ...head, missingFields: 1 }
    page.data.set('oauthProviderHead', oneMissing)
    expect(signal.get()).toEqual(oneMissing)
    const configured = {
      providerKey: 'another',
      label: 'Another provider',
      configured: true,
      missingFields: 0,
    }
    page.data.set('oauthProviderHead', configured)
    expect(signal.get()).toEqual(configured)
  })

  it('distinguishes the explicit unknown-provider null from missing or invalid data', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
    const signal = createHilosOAuthProviderHead({ scopes })
    page.data.set('oauthProviderHead', null)
    expect(signal.get()).toBeNull()
    page.data.set('oauthProviderHead', head)
    expect(signal.get()).toEqual(head)
    page.data.set('oauthProviderHead', {})
    expect(signal.get()).toBeUndefined()
    page.data.set('oauthProviderHead', null)
    expect(signal.get()).toBeNull()
    page.data.set('oauthProviderHead', undefined)
    expect(signal.get()).toBeUndefined()
  })

  it.each([
    ['a missing value', undefined],
    ['an array', []],
    ['a scalar', 'custom'],
    ['a hidden mark', { _hidden: true }],
    ['an extra field', { ...head, extra: true }],
    ...Object.keys(head).map<[string, unknown]>((field) => [
      `a missing ${field}`,
      { ...head, [field]: undefined },
    ]),
    ['a non-string key', { ...head, providerKey: 1 }],
    ['a non-string label', { ...head, label: false }],
    ['a hidden label', { ...head, label: { _hidden: true } }],
    ['a non-boolean configured value', { ...head, configured: 0 }],
    ['a hidden configured value', { ...head, configured: { _hidden: true } }],
    ...[-1, 0.5, 3, Infinity, NaN, '1', null, { _hidden: true }].map<
      [string, unknown]
    >((missingFields) => [
      `invalid missingFields ${String(missingFields)}`,
      { ...head, missingFields },
    ]),
    ['unconfigured with no missing fields', { ...head, missingFields: 0 }],
    [
      'configured with one missing field',
      { ...head, configured: true, missingFields: 1 },
    ],
    ['configured with two missing fields', { ...head, configured: true }],
  ])(
    'clears a previous head on %s without reporting an unknown provider',
    (_description, data) => {
      const scopes = new ScopeManager()
      const page = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
      const signal = createHilosOAuthProviderHead({ scopes })
      page.data.set('oauthProviderHead', head)
      expect(signal.get()).toEqual(head)

      page.data.set('oauthProviderHead', data)
      expect(signal.get()).toBeUndefined()
      page.data.set('oauthProviderHead', head)
      expect(signal.get()).toEqual(head)
    },
  )

  it('clears on foreign pages, drop and reopening the provider scope', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
    const signal = createHilosOAuthProviderHead({ scopes })
    page.data.set('oauthProviderHead', head)
    expect(signal.get()).toEqual(head)

    const foreign = scopes.openPage(HilosPages.SECURITY_OAUTH)
    foreign.data.set('oauthProviderHead', head)
    expect(signal.get()).toBeUndefined()
    foreign.data.set('oauthProviderHead', null)
    expect(signal.get()).toBeUndefined()
    page.data.set('oauthProviderHead', null)
    expect(signal.get()).toBeUndefined()

    scopes.dropPage()
    expect(signal.get()).toBeUndefined()
    const reopened = scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
    expect(signal.get()).toBeUndefined()
    reopened.data.set('oauthProviderHead', head)
    expect(signal.get()).toEqual(head)
    scopes.openPage(HilosPages.SECURITY_OAUTH_PROVIDER)
    expect(signal.get()).toBeUndefined()
  })
})
