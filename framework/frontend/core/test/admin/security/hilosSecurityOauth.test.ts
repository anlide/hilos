import { describe, expect, it } from 'vitest'

import {
  createHilosOAuthProviderFields,
  createHilosOAuthRedirect,
  createHilosSecurityOauthActions,
  resolveHilosOAuthFieldRow,
  resolveHilosOAuthProviderRow,
  resolveHilosOAuthRedirectRow,
  type HilosSecurityOauthContext,
} from '../../../src/admin/security/hilosSecurityOauth.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
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
        defaultValue: '',
        defaultSource: 'default',
        defaultSetState: false,
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
        defaultValue: 'leaked-fallback-secret',
        defaultSource: 'default',
        defaultSetState: false,
      }),
    )

    expect(row.secret).toBe(true)
    expect(row.value).toBeNull()
    expect(row.defaultValue).toBeNull()
    expect(row.setState).toBe(true)
  })

  it.each([
    [
      'A sentence the server wrote about the env.',
      'A sentence the server wrote about the env.',
    ],
    [null, null],
    [{ _hidden: true }, HIDDEN_VALUE],
  ])(
    'reads the reset reason %j as %j, on the secret row too',
    (resetDisabledReason, expected) => {
      for (const secret of [false, true]) {
        const row = resolveHilosOAuthFieldRow(
          slotRow('oauth:github/client_secret', 'field', {
            secret,
            source: 'env',
            resetDisabledReason,
          }),
        )

        expect(row.resetDisabledReason).toBe(expected)
      }
    },
  )

  it.each(['env-client', '', null, { _hidden: true }])(
    'preserves a non-secret fallback %j',
    (defaultValue) => {
      const row = resolveHilosOAuthFieldRow(
        slotRow('oauth:github/client_id', 'field', {
          secret: false,
          defaultValue,
          defaultSource: 'env',
          defaultSetState: true,
        }),
      )
      expect(row.defaultValue).toBe(
        typeof defaultValue === 'object' && defaultValue !== null
          ? HIDDEN_VALUE
          : defaultValue,
      )
      expect(row.defaultSource).toBe('env')
      expect(row.defaultSetState).toBe(true)
    },
  )

  it.each(['db', 'settings', undefined])(
    'rejects %j as a fallback source',
    (defaultSource) => {
      const field = resolveHilosOAuthFieldRow(
        slotRow('k', 'field', { defaultSource, defaultSetState: false }),
      )
      const redirect = resolveHilosOAuthRedirectRow(
        slotRow('k', 'redirect', { defaultSource, defaultSetState: false }),
      )
      expect(field.defaultSource).toBe('default')
      expect(redirect.defaultSource).toBe('default')
      expect(field.defaultSetState).toBe(false)
      expect(redirect.defaultSetState).toBe(false)
    },
  )

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
        defaultValue: '',
        defaultSource: 'default',
        defaultSetState: false,
      }),
    )

    expect(row).toEqual({
      key: 'oauth_redirect_uri',
      value: 'https://app.example/auth/callback',
      source: 'env',
      setState: true,
      defaultValue: '',
      defaultSource: 'default',
      defaultSetState: false,
    })
  })

  it('preserves a hidden mark on the return-address value', () => {
    const row = resolveHilosOAuthRedirectRow(
      slotRow('oauth_redirect_uri', 'redirect', {
        value: { _hidden: true },
        source: 'env',
        setState: true,
        defaultValue: { _hidden: true },
        defaultSource: 'default',
        defaultSetState: false,
      }),
    )

    expect(row.value).toBe(HIDDEN_VALUE)
    expect(row.defaultValue).toBe(HIDDEN_VALUE)
  })
})

describe('row focus on the tables a dialog edits over', () => {
  type Focus = { page: string; tableKey: string; rowKey: string }

  /** A context whose connection records every row-focus frame it is asked to send. */
  function focusContext(focus: Focus[]): HilosSecurityOauthContext {
    const connection = {
      sendTableViewport: () => true,
      sendTableRendered: () => true,
      sendTableRowFocus: (page: string, tableKey: string, rowKey: string) =>
        focus.push({ page, tableKey, rowKey }) > 0,
    } as unknown as HilosConnection

    return {
      connection,
      scopes: new ScopeManager(),
      actions: {} as unknown as ActionLifecycle,
    }
  }

  it('hands the return-address row into focus to the dialog over it, and lets it go with an empty key', () => {
    const focus: Focus[] = []
    const redirect = createHilosOAuthRedirect(focusContext(focus))
    expect(redirect.controller.frame.declaration?.columns).toEqual([])
    redirect.controller.ingestSubscriptionWindow(
      [
        slotRow('oauth_redirect_uri', 'redirect', {
          value: 'https://app.example/auth/callback',
          source: 'db',
          setState: true,
          defaultValue: '',
          defaultSource: 'default',
          defaultSetState: false,
        }),
      ],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(redirect.controller.focusRow('oauth_redirect_uri')?.value).toBe(
      'https://app.example/auth/callback',
    )
    expect(redirect.controller.focusedRow.get()).toMatchObject({
      defaultValue: '',
      defaultSource: 'default',
      defaultSetState: false,
    })
    redirect.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'oauth_redirect_uri',
      row: slotRow('oauth_redirect_uri', 'redirect', {
        value: 'updated',
        source: 'db',
        setState: true,
        defaultValue: 'env-fallback',
        defaultSource: 'env',
        defaultSetState: true,
      }),
    })
    expect(redirect.controller.focusedRow.get()).toMatchObject({
      defaultValue: 'env-fallback',
      defaultSource: 'env',
      defaultSetState: true,
    })
    expect(redirect.controller.rows.get()[0]?.row).toMatchObject({
      defaultValue: 'env-fallback',
      defaultSource: 'env',
      defaultSetState: true,
    })
    redirect.controller.releaseFocus()

    expect(focus).toEqual([
      {
        page: 'hilos_security_oauth',
        tableKey: 'hilosSecurityOauthRedirect',
        rowKey: 'oauth_redirect_uri',
      },
      {
        page: 'hilos_security_oauth',
        tableKey: 'hilosSecurityOauthRedirect',
        rowKey: '',
      },
    ])
  })

  it('hands a provider field row into focus to the dialog over it, and lets it go with an empty key', () => {
    const focus: Focus[] = []
    const fields = createHilosOAuthProviderFields(
      focusContext(focus),
      createSignal('oauth:github'),
    )
    expect(
      fields.controller.frame.columns
        .get()
        .find((column) => column.key === 'actions')?.reads,
    ).toEqual([
      'providerKey',
      'field',
      'label',
      'secret',
      'value',
      'source',
      'resetDisabledReason',
      'defaultValue',
      'defaultSource',
      'defaultSetState',
    ])
    // The table opens with the provider preset, so its rows come in the answer to the
    // viewport it asks for, not in the page's own window.
    fields.controller.ingestWindow(
      [
        slotRow('oauth:github/client_id', 'field', {
          providerKey: 'oauth:github',
          field: 'client_id',
          label: 'Client ID',
          type: 'string',
          secret: false,
          value: 'abc123',
          source: 'db',
          setState: true,
          defaultValue: '',
          defaultSource: 'default',
          defaultSetState: false,
        }),
      ],
      1,
      true,
      null,
      null,
      10,
    )

    expect(fields.controller.focusRow('oauth:github/client_id')?.value).toBe(
      'abc123',
    )
    expect(fields.controller.focusedRow.get()).toMatchObject({
      defaultValue: '',
      defaultSource: 'default',
      defaultSetState: false,
    })
    fields.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'oauth:github/client_id',
      row: slotRow('oauth:github/client_id', 'field', {
        providerKey: 'oauth:github',
        field: 'client_id',
        secret: false,
        value: 'updated',
        source: 'db',
        setState: true,
        defaultValue: 'env-fallback',
        defaultSource: 'env',
        defaultSetState: true,
      }),
    })
    expect(fields.controller.focusedRow.get()).toMatchObject({
      defaultValue: 'env-fallback',
      defaultSource: 'env',
      defaultSetState: true,
    })
    expect(fields.controller.rows.get()[0]?.row).toMatchObject({
      defaultValue: 'env-fallback',
      defaultSource: 'env',
      defaultSetState: true,
    })
    fields.controller.releaseFocus()

    expect(focus).toEqual([
      {
        page: 'hilos_security_oauth_provider',
        tableKey: 'hilosSecurityOauthProviderFields',
        rowKey: 'oauth:github/client_id',
      },
      {
        page: 'hilos_security_oauth_provider',
        tableKey: 'hilosSecurityOauthProviderFields',
        rowKey: '',
      },
    ])
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
