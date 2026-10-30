import type { MouseEvent } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { hilosToasts } from '@hilos/core/base'

import * as loadingButton from '../src/LoadingButton.js'
import {
  deliver,
  el,
  formHarness,
  initial,
  savedRule,
} from './hilosModerationHarness.js'

function confirm(): HTMLButtonElement {
  return el('hilos-moderation-rule-delete-confirm') as HTMLButtonElement
}

async function settle(action: () => void): Promise<void> {
  await act(async () => action())
}

describe('HilosModerationRuleDelete', () => {
  it('opens the frozen exact text under StrictMode, focuses the dialog and sends once with expectedRule until the write ack', async () => {
    const h = formHarness('delete')
    const opener = h.open()
    const dialog = h.view.getByRole('dialog', { name: 'Delete rule' })
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(dialog)
    expect(dialog.querySelector('input, textarea, select')).toBeNull()
    expect(el('hilos-moderation-rule-delete-rule').textContent).toBe(savedRule)
    expect(el('hilos-moderation-rule-delete-rule').getAttribute('title')).toBe(
      savedRule,
    )
    expect(dialog.textContent).toContain(
      'This permanently removes the rule from Profile name.',
    )
    expect(el('hilos-action-error-slot').getAttribute('role')).toBe('alert')
    expect(
      el('hilos-moderation-rule-delete-notice-slot').getAttribute('role'),
    ).toBe('status')
    fireEvent.keyDown(dialog, { key: 'Enter' })
    expect(h.writes()).toEqual([])
    confirm().focus()
    fireEvent.click(confirm())
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_delete',
        data: { id: 1, kind: initial.kind, expectedRule: savedRule },
        requestId: expect.any(String),
      },
    ])
    expect(confirm().getAttribute('aria-busy')).toBe('true')
    expect(document.activeElement).toBe(confirm())
    expect((el('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(
      (el('hilos-moderation-rule-delete-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    fireEvent.click(confirm())
    fireEvent.click(el('hilos-moderation-rule-delete-cancel'))
    fireEvent.click(el('modal-close'))
    fireEvent.click(el('modal'))
    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(h.writes()).toHaveLength(1)
    expect(h.remove.openState.get()).toBe(true)
    expect(el('modal-confirm')).toBeNull()
    // Even the deletion echo does not replace the tracked acknowledgement.
    deliver(() => h.gone())
    expect(h.remove.openState.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    await settle(() => h.reply('unrelated'))
    expect(h.remove.openState.get()).toBe(true)
    await settle(() => h.reply())
    expect(h.remove.openState.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(document.activeElement).toBe(opener)
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message, repeats }) => [severity, message, repeats]),
    ).toEqual([['success', 'Rule written.', 1]])
  })

  it('keeps a refusal in the same room, offers details and retries against the same snapshot', async () => {
    const h = formHarness('delete')
    const opener = h.open()
    const slot = el('hilos-action-error-slot')
    const text = el('hilos-moderation-rule-delete-rule')
    fireEvent.click(confirm())
    await settle(() => h.reply(undefined, false))
    expect(el('hilos-action-error-slot')).toBe(slot)
    expect(el('hilos-moderation-rule-delete-rule')).toBe(text)
    expect(text.textContent).toBe(savedRule)
    expect(confirm().disabled).toBe(false)
    expect(el('hilos-moderation-rule-delete-error').textContent).toContain(
      'The saved rule no longer matches.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    fireEvent.click(el('hilos-action-error-details'))
    const details = h.view.getByRole('dialog', {
      name: "Couldn't delete the rule",
    })
    expect(details.textContent).toContain('The writer refused this rule.')
    fireEvent.click(el('hilos-action-error-close'))
    expect(h.writes()).toHaveLength(1)
    fireEvent.click(confirm())
    await settle(() => h.reply(undefined, true, null))
    expect(h.remove.openState.get()).toBe(false)
    expect(h.writes()[1]!.data).toEqual(h.writes()[0]!.data)
    expect(hilosToasts.toasts.get()).toEqual([])
    expect(document.activeElement).toBe(opener)
    h.open()
    expect(el('hilos-action-error')).toBeNull()
    expect(confirm().disabled).toBe(false)
    fireEvent.click(el('hilos-moderation-rule-delete-cancel'))
    expect(el('modal-confirm')).toBeNull()
    expect(h.remove.openState.get()).toBe(false)
  })

  it.each(['changed', 'deleted'] as const)(
    'preserves the snapshot and blocks even the confirm handler when the row is %s',
    (change) => {
      // Spy through the real primitive to invoke the adapter callback without its
      // disabled-button guard; the SDK button and modal still render normally.
      const button = vi.spyOn(loadingButton, 'LoadingButton')
      const h = formHarness('delete')
      h.open()
      deliver(() =>
        change === 'deleted' ? h.gone() : h.incoming('A different rule'),
      )
      expect(el('hilos-moderation-rule-delete-rule').textContent).toBe(
        savedRule,
      )
      expect(el('hilos-moderation-rule-delete-notice').textContent).toContain(
        change === 'deleted'
          ? 'The rule no longer exists.'
          : 'The rule changed. Close this window and confirm again.',
      )
      expect(confirm().disabled).toBe(true)
      const callback = button.mock.calls.at(-1)![0].onClick!
      deliver(() => callback({} as MouseEvent<HTMLButtonElement>))
      expect(h.writes()).toEqual([])
      expect(h.remove.openState.get()).toBe(true)
      fireEvent.click(el('hilos-moderation-rule-delete-cancel'))
      expect(h.remove.openState.get()).toBe(false)
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    },
  )

  it.each([{ viewer: true }, { takeover: true }])(
    'allows inspection and Cancel but blocks a forged confirm in look-only mode %j',
    (mode) => {
      const button = vi.spyOn(loadingButton, 'LoadingButton')
      const h = formHarness('delete', mode)
      h.open()
      expect(confirm().disabled).toBe(true)
      expect(confirm().getAttribute('aria-describedby')).toBeTruthy()
      expect(el('hilos-moderation-rule-delete-rule').textContent).toBe(
        savedRule,
      )
      const callback = button.mock.calls.at(-1)![0].onClick!
      deliver(() => callback({} as MouseEvent<HTMLButtonElement>))
      expect(h.writes()).toEqual([])
      fireEvent.click(el('hilos-moderation-rule-delete-cancel'))
      expect(h.remove.openState.get()).toBe(false)
    },
  )

  it.each([
    { kind: initial.kind, success: true },
    { kind: initial.kind, success: false },
    { kind: 'hilos_profile_photo', success: true },
    { kind: 'hilos_profile_photo', success: false },
  ])(
    'isolates a late $success result after replacement with $kind, keeping the new confirmation busy',
    async ({ kind, success }) => {
      const h = formHarness('delete')
      h.open()
      fireEvent.click(confirm())
      const oldId = String(h.writes()[0]!.requestId)
      deliver(() => {
        h.replace(kind)
        h.remove.open('1')
      })
      expect(confirm().getAttribute('aria-busy')).toBeNull()
      expect(el('hilos-action-error')).toBeNull()
      fireEvent.click(confirm())
      expect(h.writes()).toHaveLength(2)
      expect(h.writes()[1]!.data).toEqual({
        id: 1,
        kind,
        expectedRule: savedRule,
      })
      const snapshot = el('hilos-moderation-rule-delete-rule')
      await settle(() => h.reply(oldId, success))
      expect(el('hilos-moderation-rule-delete-rule')).toBe(snapshot)
      expect(confirm().getAttribute('aria-busy')).toBe('true')
      expect(el('hilos-action-error')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      await settle(() => h.reply())
      expect(h.remove.openState.get()).toBe(false)
      expect(hilosToasts.toasts.get().map(({ repeats }) => repeats)).toEqual([
        1,
      ])
    },
  )
})
