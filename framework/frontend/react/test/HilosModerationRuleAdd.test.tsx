import { act, fireEvent, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { hilosToasts, HILOS_VIEW_MODE_STRIP_TEXT_ID } from '@hilos/core/base'

import { deliver, el, formHarness, initial } from './hilosModerationHarness.js'

function field(): HTMLTextAreaElement {
  return el('hilos-moderation-rule-add-rule') as HTMLTextAreaElement
}

function save(): HTMLButtonElement {
  return el('hilos-moderation-rule-add-submit') as HTMLButtonElement
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

describe('HilosModerationRuleAdd', () => {
  it('opens a named modal under StrictMode with one labeled field, a permanent refusal slot and focus return', () => {
    const h = formHarness('add')
    const opener = h.open()
    const dialog = h.view.getByRole('dialog', {
      name: 'Add rule · Profile name',
    })
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(within(dialog).getByRole('textbox', { name: 'Rule' })).toBe(field())
    expect(dialog.querySelectorAll('textarea, input, select')).toHaveLength(1)
    expect(document.activeElement).toBe(field())
    expect(el('hilos-action-error-slot').getAttribute('role')).toBe('alert')
    expect(el('hilos-action-error-slot').getAttribute('aria-live')).toBe(
      'assertive',
    )
    expect(el('hilos-action-error-idle').getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(save().form).toBe(field().form)
    fireEvent.click(el('hilos-moderation-rule-add-cancel'))
    expect(h.view.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(h.writes()).toEqual([])
  })

  it.each(['', ' ', '\n\t '])(
    'guards the button and forged submit for blank %j',
    (value) => {
      const h = formHarness('add')
      h.open()
      input(value)
      expect(save().disabled).toBe(true)
      submit()
      expect(h.writes()).toEqual([])
      expect(field().value).toBe(value)
      expect(h.add.openState.get()).toBe(true)
    },
  )

  it('keeps Enter as text input, sends raw text once, locks busy close paths and waits for the real ack', async () => {
    const h = formHarness('add')
    const opener = h.open()
    const textarea = field()
    const raw = '  Keep punctuation: +48.\n\n  Raw rule.\n'
    input(raw)
    const enter = new KeyboardEvent('keydown', {
      key: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    fireEvent(textarea, enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(h.writes()).toEqual([])
    save().focus()
    fireEvent.click(save())
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_add',
        data: { kind: initial.kind, rule: raw },
        requestId: expect.any(String),
      },
    ])
    expect(save().getAttribute('aria-busy')).toBe('true')
    expect(textarea.matches(':disabled')).toBe(true)
    expect((el('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(
      (el('hilos-moderation-rule-add-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(document.activeElement).toBe(save())
    expect(el('loading-button-spinner')).toBeNull()
    fireEvent.click(save())
    submit()
    fireEvent.keyDown(textarea, { key: 'Escape' })
    fireEvent.click(el('modal'))
    fireEvent.click(el('modal-close'))
    fireEvent.click(el('hilos-moderation-rule-add-cancel'))
    expect(el('modal-confirm')).toBeNull()
    expect(field()).toBe(textarea)
    expect(field().value).toBe(raw)
    expect(h.writes()).toHaveLength(1)
    await settle(() => h.reply('unrelated'))
    expect(h.add.openState.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    await settle(() => h.reply())
    expect(h.view.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message, repeats }) => [severity, message, repeats]),
    ).toEqual([['success', 'Rule written.', 1]])
  })

  it('holds the server refusal beside the unchanged draft, exposes details and retries only on submit', async () => {
    const h = formHarness('add')
    h.open()
    const textarea = field()
    const slot = el('hilos-action-error-slot')
    input('  Draft\n')
    submit()
    await settle(() => h.reply(undefined, false))
    expect(field()).toBe(textarea)
    expect(field().value).toBe('  Draft\n')
    expect(field().matches(':disabled')).toBe(false)
    expect(el('hilos-action-error-slot')).toBe(slot)
    expect(el('hilos-moderation-rule-add-error').textContent).toContain(
      'The saved rule no longer matches.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    fireEvent.click(el('hilos-action-error-details'))
    const details = h.view.getByRole('dialog', {
      name: "Couldn't add the rule",
    })
    expect(details.textContent).toContain('The writer refused this rule.')
    fireEvent.click(el('hilos-action-error-close'))
    expect(field()).toBe(textarea)
    expect(h.writes()).toHaveLength(1)
    submit()
    await settle(() => h.reply(undefined, true, null))
    expect(h.add.openState.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('asks before discard, keeps the draft on Keep editing and reopens the same kind with a clean runner', async () => {
    const h = formHarness('add')
    const opener = h.open()
    input('Dirty draft')
    submit()
    await settle(() => h.reply(undefined, false))
    fireEvent.click(el('hilos-moderation-rule-add-cancel'))
    const confirm = h.view.getByRole('alertdialog', {
      name: 'Discard changes?',
    })
    expect(document.activeElement).toBe(confirm)
    fireEvent.click(el('modal-confirm-cancel'))
    expect(field().value).toBe('Dirty draft')
    expect(document.activeElement).toBe(field())
    fireEvent.keyDown(field(), { key: 'Escape' })
    fireEvent.click(el('modal-confirm-discard'))
    expect(document.activeElement).toBe(opener)
    h.open()
    expect(field().value).toBe('')
    expect(save().disabled).toBe(true)
    expect(el('hilos-action-error')).toBeNull()
  })

  it.each([{ viewer: true }, { takeover: true }])(
    'permits a local draft but refuses a forged form submit in look-only mode %j',
    (mode) => {
      const h = formHarness('add', mode)
      h.open()
      input('Local draft')
      expect(field().matches(':disabled')).toBe(false)
      expect(save().disabled).toBe(true)
      if (mode.viewer)
        expect(save().getAttribute('aria-describedby')).toBe(
          HILOS_VIEW_MODE_STRIP_TEXT_ID,
        )
      submit()
      expect(h.writes()).toEqual([])
      fireEvent.click(el('hilos-moderation-rule-add-cancel'))
      fireEvent.click(el('modal-confirm-discard'))
      expect(h.add.openState.get()).toBe(false)
    },
  )

  it.each([
    { kind: initial.kind, success: true },
    { kind: initial.kind, success: false },
    { kind: 'hilos_profile_photo', success: true },
    { kind: 'hilos_profile_photo', success: false },
  ])(
    'isolates a late $success result after scope/kind replacement with $kind, even when reopen is batched',
    async ({ kind, success }) => {
      const h = formHarness('add')
      h.open()
      input('Old draft')
      submit()
      const oldId = String(h.writes()[0]!.requestId)
      deliver(() => {
        h.replace(kind)
        h.add.open()
      })
      expect(field().value).toBe('')
      input('Replacement draft')
      submit()
      const textarea = field()
      expect(h.writes()).toHaveLength(2)
      expect(h.writes()[1]!.data).toEqual({ kind, rule: 'Replacement draft' })
      await settle(() => h.reply(oldId, success))
      expect(field()).toBe(textarea)
      expect(field().value).toBe('Replacement draft')
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(el('hilos-action-error')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      await settle(() => h.reply())
      expect(h.add.openState.get()).toBe(false)
      expect(hilosToasts.toasts.get().map(({ repeats }) => repeats)).toEqual([
        1,
      ])
    },
  )

  it('drops the old window voice after unmount while a write is pending', async () => {
    const h = formHarness('add')
    h.open()
    input('Pending')
    submit()
    h.view.unmount()
    await settle(() => h.reply())
    expect(hilosToasts.toasts.get()).toEqual([])
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })
})
