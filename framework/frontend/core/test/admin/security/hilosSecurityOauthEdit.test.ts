// Covers what is the OAuth windows' own over the row-edit session (HIL-1279):
// the return address kept as typed and sent trimmed, the dash for an empty
// address elsewhere; the provider field's secret forgotten on close and the
// dash for an empty value elsewhere.
import { describe, expect, it, vi } from 'vitest'
import {
  createHilosOauthProviderFieldEdit,
  createHilosOauthRedirectEdit,
} from '../../../src/admin/security/hilosSecurityOauthEdit.js'
import {
  type HilosOAuthFieldRow,
  type HilosOAuthRedirectRow,
  type HilosSecurityOauthActions,
} from '../../../src/admin/security/hilosSecurityOauth.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableViewportController } from '../../../src/table/TableViewportController.js'

function controllerOf<R extends { key: string }>(rows: R[]) {
  const focused = createSignal<R | undefined>(undefined)
  const controller = {
    focusRow: (key: string) => {
      const found = rows.find((candidate) => candidate.key === key) ?? null
      if (found !== null) focused.set(found)
      return found
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<R>

  return { controller, focused }
}

function actionsSpy() {
  const sent: unknown[][] = []
  const actions = {
    sendRedirectSet: (...args: unknown[]) => (
      sent.push(['redirect', ...args]),
      {} as ActionHandle
    ),
    sendProviderSet: (...args: unknown[]) => (
      sent.push(['provider', ...args]),
      {} as ActionHandle
    ),
  } as unknown as HilosSecurityOauthActions

  return { sent, actions }
}

const address = {
  key: 'auth.oauth.redirect',
  value: 'https://a.example/back',
  source: 'db',
  setState: true,
} as HilosOAuthRedirectRow

describe('OAuth return-address window', () => {
  it('keeps the address as typed in the draft and sends it trimmed', async () => {
    const { controller } = controllerOf([address])
    const { sent, actions } = actionsSpy()
    const edit = createHilosOauthRedirectEdit(controller, actions)
    edit.start()
    edit.open(address.key)
    edit.patchForm({ text: ' https://b.example/back ' })
    expect(edit.state.get().dirty).toBe(true)
    await edit.save(vi.fn(() => Promise.resolve(true)))
    expect(sent).toEqual([['redirect', 'https://b.example/back']])
  })

  it('says a dash for an address emptied elsewhere', () => {
    const { controller, focused } = controllerOf([address])
    const edit = createHilosOauthRedirectEdit(controller, actionsSpy().actions)
    edit.start()
    edit.open(address.key)
    edit.patchForm({ text: 'https://b.example/back' })
    focused.set({ ...address, value: '' })
    expect(edit.noticeText.get()).toBe('Changed elsewhere to "—".')
  })
})

const secret = {
  key: 'google.client_secret',
  providerKey: 'google',
  field: 'client_secret',
  label: 'Client secret',
  type: 'string',
  secret: true,
  value: null,
  source: 'db',
  setState: true,
} as HilosOAuthFieldRow

describe('OAuth provider field window', () => {
  it('opens the secret empty, sends what was typed, and forgets it on close', async () => {
    const { controller } = controllerOf([secret])
    const { sent, actions } = actionsSpy()
    const edit = createHilosOauthProviderFieldEdit(controller, actions)
    edit.start()
    edit.open(secret.key)
    expect(edit.form.get()).toEqual({ value: '' })
    expect(edit.canSave.get()).toBe(false)
    edit.setForm({ value: 'shh' })
    await edit.save(vi.fn(() => Promise.resolve(false)))
    expect(sent).toEqual([['provider', 'google', 'client_secret', 'shh']])
    expect(edit.opened.get()).toBe(true)
    edit.close()
    expect(edit.form.get()).toEqual({ value: '' })
  })

  it('says a dash for a value emptied elsewhere', () => {
    const scope = {
      ...secret,
      key: 'google.scope',
      field: 'scope',
      secret: false,
      value: 'email',
    } as HilosOAuthFieldRow
    const { controller, focused } = controllerOf([scope])
    const edit = createHilosOauthProviderFieldEdit(
      controller,
      actionsSpy().actions,
    )
    edit.start()
    edit.open(scope.key)
    edit.setForm({ value: 'profile' })
    focused.set({ ...scope, value: null })
    expect(edit.noticeText.get()).toBe('Changed elsewhere to "—".')
  })
})
