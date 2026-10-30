import { act, fireEvent, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DEFAULT_SPINNER_DELAY_MS, hilosToasts } from '@hilos/core/base'

import {
  deliver,
  el,
  formHarness,
  initial,
  savedRule,
} from './hilosModerationHarness.js'

function field(): HTMLTextAreaElement {
  return el('hilos-moderation-rule-edit-rule') as HTMLTextAreaElement
}

function save(): HTMLButtonElement {
  return el('hilos-moderation-rule-edit-submit') as HTMLButtonElement
}

function input(value: string): void {
  fireEvent.change(field(), { target: { value } })
}

function submit(): void {
  fireEvent.submit(field().form!)
}

async function settle(action: () => void): Promise<void> {
  await act(async () => action())
}

describe('HilosModerationRuleEdit', () => {
  it('opens the focused row under StrictMode with a named textarea, permanent refusal/notice rooms and no write for an untouched edit', () => {
    const h = formHarness('edit')
    const opener = h.open()
    const dialog = h.view.getByRole('dialog', {
      name: 'Edit rule · Profile name',
    })
    expect(within(dialog).getByRole('textbox', { name: 'Rule' })).toBe(field())
    expect(field().value).toBe(savedRule)
    expect(document.activeElement).toBe(field())
    expect(h.rules.controller.focusedRow.get()?.id).toBe(1)
    expect(el('hilos-action-error-slot').getAttribute('role')).toBe('alert')
    expect(
      el('hilos-moderation-rule-edit-notice-slot').getAttribute('role'),
    ).toBe('status')
    expect(
      el('hilos-moderation-rule-edit-notice-idle').getAttribute('aria-hidden'),
    ).toBe('true')
    expect(el('conflict-choices-idle').getAttribute('aria-hidden')).toBe('true')
    expect(save().disabled).toBe(true)
    submit()
    expect(h.writes()).toEqual([])
    expect(h.edit.opened.get()).toBe(true)
    fireEvent.click(el('hilos-moderation-rule-edit-cancel'))
    expect(h.view.queryByRole('dialog')).toBeNull()
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(document.activeElement).toBe(opener)
  })

  it.each(['', '\n \t '])(
    'blocks empty draft %j in both save paths',
    (value) => {
      const h = formHarness('edit')
      h.open()
      input(value)
      expect(save().disabled).toBe(true)
      submit()
      expect(h.writes()).toEqual([])
      expect(field().value).toBe(value)
    },
  )

  it('follows untouched incoming, resolves theirs/mine and sends the raw draft with exact accepted expectedRule', async () => {
    const h = formHarness('edit')
    const opener = h.open()
    const slot = el('hilos-moderation-rule-edit-notice-slot')
    deliver(() => h.incoming('  Accepted elsewhere.\n'))
    expect(field().value).toBe('  Accepted elsewhere.\n')
    expect(el('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Updated just now',
    )
    expect(save().disabled).toBe(true)
    const raw = '  My raw\n\nrule.\n'
    input(raw)
    deliver(() => h.incoming('Incoming one'))
    expect(el('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Rule changed elsewhere to "Incoming one".',
    )
    expect(save().disabled).toBe(true)
    submit()
    expect(h.writes()).toEqual([])
    expect(el('conflict-merge')).toBeNull()
    fireEvent.click(el('conflict-accept-theirs'))
    expect(field().value).toBe('Incoming one')
    expect(save().disabled).toBe(true)
    input(raw)
    const expectedRule = '  Incoming two.\n'
    deliver(() => h.incoming(expectedRule))
    fireEvent.click(el('conflict-accept-mine'))
    expect(field().value).toBe(raw)
    expect(save().disabled).toBe(false)
    expect(el('hilos-moderation-rule-edit-notice-slot')).toBe(slot)
    const enter = new KeyboardEvent('keydown', {
      key: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    fireEvent(field(), enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(h.writes()).toEqual([])
    fireEvent.click(save())
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_update',
        data: { id: 1, kind: initial.kind, rule: raw, expectedRule },
        requestId: expect.any(String),
      },
    ])
    // A row update alone never acknowledges the write.
    deliver(() => h.incoming(raw))
    expect(h.edit.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    await settle(() => h.reply('unrelated'))
    expect(h.edit.opened.get()).toBe(true)
    await settle(() => h.reply())
    expect(h.edit.opened.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(document.activeElement).toBe(opener)
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message, repeats }) => [severity, message, repeats]),
    ).toEqual([['success', 'Rule written.', 1]])
  })

  it('keeps busy fields and all close paths locked through the spinner delay, then holds the refusal and draft for retry', async () => {
    vi.useFakeTimers()
    const h = formHarness('edit')
    h.open()
    input('  Refused draft\n')
    const textarea = field()
    const errorSlot = el('hilos-action-error-slot')
    save().focus()
    fireEvent.click(save())
    expect(textarea.matches(':disabled')).toBe(true)
    expect(save().getAttribute('aria-busy')).toBe('true')
    expect(document.activeElement).toBe(save())
    expect(el('loading-button-spinner')).toBeNull()
    expect((el('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(
      (el('hilos-moderation-rule-edit-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    submit()
    fireEvent.click(save())
    fireEvent.keyDown(textarea, { key: 'Escape' })
    fireEvent.click(el('modal'))
    fireEvent.click(el('modal-close'))
    fireEvent.click(el('hilos-moderation-rule-edit-cancel'))
    expect(h.writes()).toHaveLength(1)
    expect(el('modal-confirm')).toBeNull()
    await act(async () => vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS))
    expect(el('loading-button-spinner')).not.toBeNull()
    expect(field()).toBe(textarea)
    await settle(() => h.reply(undefined, false))
    expect(field()).toBe(textarea)
    expect(field().value).toBe('  Refused draft\n')
    expect(field().matches(':disabled')).toBe(false)
    expect(el('hilos-action-error-slot')).toBe(errorSlot)
    expect(el('hilos-moderation-rule-edit-error').textContent).toContain(
      'The saved rule no longer matches.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    fireEvent.click(el('hilos-action-error-details'))
    expect(
      h.view.getByRole('dialog', { name: "Couldn't save the rule" })
        .textContent,
    ).toContain('The writer refused this rule.')
    fireEvent.click(el('hilos-action-error-close'))
    submit()
    await settle(() => h.reply())
    expect(h.edit.opened.get()).toBe(false)
    expect(h.writes()).toHaveLength(2)
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })

  it('keeps a deleted row draft extractable, blocks forged submit and asks before discard', () => {
    const h = formHarness('edit')
    h.open()
    input('Keep this draft')
    deliver(() => h.gone())
    expect(field().value).toBe('Keep this draft')
    expect(field().matches(':disabled')).toBe(false)
    expect(save().textContent).toBe('Deleted')
    expect(save().disabled).toBe(true)
    expect(el('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Deleted elsewhere — your text stays to copy.',
    )
    submit()
    expect(h.writes()).toEqual([])
    field().select()
    expect(
      field().value.slice(field().selectionStart, field().selectionEnd),
    ).toBe('Keep this draft')
    fireEvent.click(el('hilos-moderation-rule-edit-cancel'))
    expect(
      h.view.getByRole('alertdialog', { name: 'Discard changes?' }),
    ).not.toBeNull()
    fireEvent.click(el('modal-confirm-cancel'))
    expect(field().value).toBe('Keep this draft')
    expect(document.activeElement).toBe(field())
    fireEvent.keyDown(field(), { key: 'Escape' })
    fireEvent.click(el('modal-confirm-discard'))
    expect(h.edit.opened.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
  })

  it.each([{ viewer: true }, { takeover: true }])(
    'keeps local conflict choices live but blocks a forged write in look-only mode %j',
    (mode) => {
      const h = formHarness('edit', mode)
      h.open()
      input('Local draft')
      deliver(() => h.incoming('Other draft'))
      fireEvent.click(el('conflict-accept-mine'))
      expect(field().value).toBe('Local draft')
      expect(field().matches(':disabled')).toBe(false)
      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toBeTruthy()
      submit()
      expect(h.writes()).toEqual([])
      fireEvent.click(el('hilos-moderation-rule-edit-cancel'))
      fireEvent.click(el('modal-confirm-discard'))
      expect(h.edit.opened.get()).toBe(false)
    },
  )

  it.each([
    { kind: initial.kind, success: true },
    { kind: initial.kind, success: false },
    { kind: 'hilos_profile_photo', success: true },
    { kind: 'hilos_profile_photo', success: false },
  ])(
    'isolates a late $success result after replacement with $kind and resets the runner for the same row id',
    async ({ kind, success }) => {
      const h = formHarness('edit')
      h.open()
      input('Old draft')
      submit()
      const oldId = String(h.writes()[0]!.requestId)
      deliver(() => {
        h.replace(kind)
        h.edit.open('1')
      })
      expect(field().value).toBe(savedRule)
      input('New draft')
      submit()
      const textarea = field()
      expect(h.writes()).toHaveLength(2)
      expect(h.writes()[1]!.data).toEqual({
        id: 1,
        kind,
        rule: 'New draft',
        expectedRule: savedRule,
      })
      await settle(() => h.reply(oldId, success))
      expect(field()).toBe(textarea)
      expect(field().value).toBe('New draft')
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(el('hilos-action-error')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      await settle(() => h.reply())
      expect(h.edit.opened.get()).toBe(false)
      expect(hilosToasts.toasts.get().map(({ repeats }) => repeats)).toEqual([
        1,
      ])
      h.open()
      expect(field().value).toBe(savedRule)
      expect(save().getAttribute('aria-busy')).toBeNull()
      expect(el('hilos-action-error')).toBeNull()
    },
  )
})
