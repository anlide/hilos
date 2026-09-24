// Covers the sign-in methods admin headless (HIL-427): the row resolver, the live
// enabled set the switches read instead of the row, and the switch dispatch.
import { describe, expect, it } from 'vitest'

import {
  createHilosSignInMethodsActions,
  createHilosSignInMethodsTable,
  resolveHilosSignInMethodRow,
  type HilosSignInMethodsContext,
} from '../../../src/admin/security/hilosSecuritySignInMethods.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a row whose inline `method` slot carries the given fields. */
function methodRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { method: slot } }
}

describe('resolveHilosSignInMethodRow', () => {
  it('maps every slot field onto the view-model', () => {
    expect(
      resolveHilosSignInMethodRow(
        methodRow('oauth:github', {
          methodKey: 'oauth:github',
          label: 'GitHub',
          enabled: true,
          ready: false,
          providerKey: 'oauth:github',
        }),
      ),
    ).toEqual({
      methodKey: 'oauth:github',
      label: 'GitHub',
      enabled: true,
      ready: false,
      providerKey: 'oauth:github',
    })
  })

  it('falls back to the row key, names the method by it, and links nowhere', () => {
    expect(
      resolveHilosSignInMethodRow(methodRow('passkey', undefined)),
    ).toEqual({
      methodKey: 'passkey',
      label: 'passkey',
      enabled: false,
      ready: false,
      providerKey: null,
    })
  })
})

describe('createHilosSignInMethodsTable', () => {
  it('reads whether a method is on from the live set, not from a row', () => {
    const scopes = new ScopeManager()
    const table = createHilosSignInMethodsTable({
      connection: {},
      scopes,
      actions: {},
    } as unknown as HilosSignInMethodsContext)

    scopes.session.data.set('authMethods', [
      { key: 'password', name: null },
      { key: 'sms', name: null },
    ])
    expect(table.enabledKeys.get()).toEqual(['password', 'sms'])

    // The frame the settings library sends after a switch lands in the same slot.
    scopes.session.data.set('authMethods', [{ key: 'password', name: null }])
    expect(table.enabledKeys.get()).toEqual(['password'])
  })

  it('keeps an enabled provider on while it is not ready (HIL-1080)', () => {
    const scopes = new ScopeManager()
    const table = createHilosSignInMethodsTable({
      connection: {},
      scopes,
      actions: {},
    } as unknown as HilosSignInMethodsContext)

    scopes.session.data.set('authMethods', [
      { key: 'password', name: null, ready: true },
      { key: 'oauth:github', name: 'GitHub', ready: false },
    ])
    expect(table.enabledKeys.get()).toEqual(['password', 'oauth:github'])
  })
})

describe('createHilosSignInMethodsActions', () => {
  it('dispatches one method and whether it should be on', () => {
    const calls: Array<{ action: string; payload: Record<string, unknown> }> =
      []
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
    } as unknown as HilosSignInMethodsContext

    createHilosSignInMethodsActions(context).sendMethodSet('sms', false)

    expect(calls).toEqual([
      {
        action: 'security_sign_in_method_set',
        payload: { methodKey: 'sms', enabled: false },
      },
    ])
  })
})
