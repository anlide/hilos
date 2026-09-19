import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityOauthActions,
  resolveHilosOAuthFieldRow,
  resolveHilosOAuthProviderRow,
  resolveHilosOAuthRedirectRow,
  type HilosSecurityOauthContext,
} from '../../../src/admin/security/hilosSecurityOauth.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a row whose inline slot of the given name carries the given fields. */
function slotRow(
  rowKey: string,
  slotName: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { [slotName]: slot } }
}

describe('resolveHilosOAuthProviderRow', () => {
  it('maps every slot field onto the view-model', () => {
    const row = resolveHilosOAuthProviderRow(
      slotRow('oauth:github', 'provider', {
        providerKey: 'oauth:github',
        label: 'GitHub',
        builtIn: true,
        configured: false,
        missingFields: 1,
        secretSet: false,
        clientIdSource: 'env',
        authorizeUrl: 'https://github.com/login/oauth/authorize',
        tokenUrl: 'https://github.com/login/oauth/access_token',
        userInfoUrl: 'https://api.github.com/user',
        subjectKey: 'id',
        emailKey: 'email',
        nameKey: 'login',
      }),
    )

    expect(row).toEqual({
      providerKey: 'oauth:github',
      label: 'GitHub',
      builtIn: true,
      configured: false,
      missingFields: 1,
      secretSet: false,
      clientIdSource: 'env',
      authorizeUrl: 'https://github.com/login/oauth/authorize',
      tokenUrl: 'https://github.com/login/oauth/access_token',
      userInfoUrl: 'https://api.github.com/user',
      subjectKey: 'id',
      emailKey: 'email',
      nameKey: 'login',
    })
  })

  it('falls back to the row key and the default source when the slot is absent', () => {
    const row = resolveHilosOAuthProviderRow(
      slotRow('oauth:google', 'provider', undefined),
    )

    expect(row.providerKey).toBe('oauth:google')
    expect(row.label).toBe('oauth:google')
    expect(row.clientIdSource).toBe('default')
    expect(row.configured).toBe(false)
  })
})

describe('resolveHilosOAuthFieldRow', () => {
  it('keeps an ordinary field value and its source', () => {
    const row = resolveHilosOAuthFieldRow(
      slotRow('oauth:github/client_id', 'field', {
        providerKey: 'oauth:github',
        field: 'client_id',
        label: 'Client ID',
        type: 'string',
        secret: false,
        value: 'abc123',
        source: 'db',
        setState: true,
      }),
    )

    expect(row.key).toBe('oauth:github/client_id')
    expect(row.value).toBe('abc123')
    expect(row.source).toBe('db')
    expect(row.setState).toBe(true)
  })

  it('never reads a value out of the secret row, whatever the slot holds', () => {
    const row = resolveHilosOAuthFieldRow(
      slotRow('oauth:github/client_secret', 'field', {
        providerKey: 'oauth:github',
        field: 'client_secret',
        label: 'Client secret',
        type: 'string',
        secret: true,
        value: 'leaked-secret',
        source: 'db',
        setState: true,
      }),
    )

    expect(row.secret).toBe(true)
    expect(row.value).toBeNull()
    expect(row.setState).toBe(true)
  })

  it('narrows an unknown source to the default one', () => {
    const row = resolveHilosOAuthFieldRow(
      slotRow('oauth:github/scope', 'field', {
        field: 'scope',
        source: 'settings',
      }),
    )

    expect(row.source).toBe('default')
    expect(row.label).toBe('scope')
  })
})

describe('resolveHilosOAuthRedirectRow', () => {
  it('maps the one return-address row', () => {
    const row = resolveHilosOAuthRedirectRow(
      slotRow('oauth_redirect_uri', 'redirect', {
        value: 'https://app.example/auth/callback',
        source: 'env',
        setState: true,
      }),
    )

    expect(row).toEqual({
      key: 'oauth_redirect_uri',
      value: 'https://app.example/auth/callback',
      source: 'env',
      setState: true,
    })
  })
})

describe('createHilosSecurityOauthActions', () => {
  /** A context whose action lifecycle records every dispatch. */
  function recordingContext(): {
    context: HilosSecurityOauthContext
    calls: Array<{ action: string; payload: Record<string, unknown> }>
  } {
    const calls: Array<{
      action: string
      payload: Record<string, unknown>
    }> = []
    const context = {
      connection: {},
      scopes: {},
      actions: {
        dispatch(
          action: string,
          payload: Record<string, unknown>,
        ): ActionHandle {
          calls.push({ action, payload })

          return {} as ActionHandle
        },
      },
    } as unknown as HilosSecurityOauthContext

    return { context, calls }
  }

  it('dispatches a provider field write with the provider, field, and value', () => {
    const { context, calls } = recordingContext()
    createHilosSecurityOauthActions(context).sendProviderSet(
      'oauth:github',
      'client_secret',
      'new-secret',
    )

    expect(calls).toEqual([
      {
        action: 'security_oauth_provider_set',
        payload: {
          providerKey: 'oauth:github',
          field: 'client_secret',
          value: 'new-secret',
        },
      },
    ])
  })

  it('dispatches a provider field reset with the provider and field', () => {
    const { context, calls } = recordingContext()
    createHilosSecurityOauthActions(context).sendProviderReset(
      'oauth:github',
      'scope',
    )

    expect(calls).toEqual([
      {
        action: 'security_oauth_provider_reset',
        payload: { providerKey: 'oauth:github', field: 'scope' },
      },
    ])
  })

  it('dispatches the return-address write and reset', () => {
    const { context, calls } = recordingContext()
    const actions = createHilosSecurityOauthActions(context)
    actions.sendRedirectSet('https://app.example/auth/callback')
    actions.sendRedirectReset()

    expect(calls).toEqual([
      {
        action: 'security_oauth_redirect_set',
        payload: { value: 'https://app.example/auth/callback' },
      },
      { action: 'security_oauth_redirect_reset', payload: {} },
    ])
  })
})
