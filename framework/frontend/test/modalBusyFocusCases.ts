/** Shared busy-focus scenarios, registered against each SDK's real modal. */
import type { expect, it } from 'vitest'

/** Inputs for the small modal fixture rendered by each view adapter. */
export interface ModalBusyFocusProps {
  readonly open: boolean
  readonly busy: boolean
  readonly body: 'default' | 'custom'
  /** An independent native disabled reason on the second field. */
  readonly disabled: boolean
  /** Changing the step replaces the fields, keeping their static data ids. */
  readonly step: 'first' | 'next'
  readonly initialFocus: '' | 'dialog' | 'inner'
  /** Two available footer controls, outside the busy body lock. */
  readonly actions: boolean
  /** A real submitting LoadingButton outside the custom body's field lock. */
  readonly submitting: boolean
  readonly onSubmit?: () => void
}

/** Only framework lifecycle and event delivery belong to an adapter. */
export interface ModalBusyFocusView {
  /** Read the current document body, including the portal or teleport. */
  readonly container: HTMLElement
  update(props: Partial<ModalBusyFocusProps>): Promise<void>
  key(
    target: HTMLElement,
    key: string,
    shiftKey?: boolean,
  ): Promise<KeyboardEvent>
  flush(): Promise<void>
  unmount(): Promise<void>
}

/** A framework-local mount of the real component and its fixture content. */
export type MountModalBusyFocus = (
  props: ModalBusyFocusProps,
) => Promise<ModalBusyFocusView>

/** Vitest belongs to the SDK's local discovery entry. */
export interface ModalBusyFocusRunner {
  readonly it: typeof it
  readonly expect: typeof expect
}

/**
 * Register one text of busy-focus steps and explicit outcomes for all SDKs.
 *
 * @param runner The local Vitest registration and assertions.
 * @param mount The SDK's real modal fixture and lifecycle adapter.
 */
export function registerModalBusyFocusCases(
  runner: ModalBusyFocusRunner,
  mount: MountModalBusyFocus,
): void {
  const { it, expect } = runner
  const mountModal = (props: Partial<ModalBusyFocusProps> = {}) =>
    mount({
      open: true,
      busy: false,
      body: 'default',
      disabled: false,
      step: 'first',
      initialFocus: '',
      actions: true,
      submitting: false,
      ...props,
    })

  function find<T extends HTMLElement = HTMLElement>(
    view: ModalBusyFocusView,
    id: string,
  ): T {
    const element = view.container.querySelector<T>(`[data-id="${id}"]`)
    expect(element).not.toBeNull()
    return element as T
  }

  for (const body of ['default', 'custom'] as const) {
    it(`${body} body: parks and returns to the exact second, unmarked field`, async () => {
      const view = await mountModal({ body })
      try {
        const first = find(view, 'busy-focus-first')
        const second = find<HTMLInputElement>(view, 'busy-focus-second')
        const root = find(view, 'modal')
        expect(view.container.ownerDocument.activeElement).toBe(first)
        expect(second.hasAttribute('data-autofocus')).toBe(false)
        second.value = 'draft in the second field'
        second.focus()
        expect(view.container.ownerDocument.activeElement).toBe(second)

        await view.update({ busy: true })
        expect(second.matches(':disabled')).toBe(true)
        expect(second.hasAttribute('disabled')).toBe(body === 'custom')
        expect(view.container.ownerDocument.activeElement).toBe(root)

        await view.update({ busy: false })
        expect(find(view, 'busy-focus-second')).toBe(second)
        expect(second.matches(':disabled')).toBe(false)
        expect(second.value).toBe('draft in the second field')
        expect(view.container.ownerDocument.activeElement).toBe(second)

        // A later cycle captures its own field rather than retaining the second.
        first.focus()
        await view.update({ busy: true })
        expect(view.container.ownerDocument.activeElement).toBe(root)
        await view.update({ busy: false })
        expect(view.container.ownerDocument.activeElement).toBe(first)
      } finally {
        await view.unmount()
      }
    })

    it(`${body} body: an independently disabled captured field forbids initial-focus fallback`, async () => {
      const view = await mountModal({ body })
      try {
        const second = find(view, 'busy-focus-second')
        const root = find(view, 'modal')
        second.focus()
        await view.update({ busy: true })
        expect(view.container.ownerDocument.activeElement).toBe(root)

        await view.update({ busy: false, disabled: true })
        expect(second.matches(':disabled')).toBe(true)
        expect(view.container.ownerDocument.activeElement).toBe(root)

        // Merely making the field available later does not replay the return.
        await view.update({ disabled: false })
        expect(second.matches(':disabled')).toBe(false)
        expect(view.container.ownerDocument.activeElement).toBe(root)
      } finally {
        await view.unmount()
      }
    })
  }

  for (const unavailable of ['hidden', 'inert', 'aria-disabled'] as const) {
    it(`independent availability: a captured field with ${unavailable} forbids fallback`, async () => {
      const view = await mountModal()
      try {
        const second = find(view, 'busy-focus-second')
        const root = find(view, 'modal')
        second.focus()
        await view.update({ busy: true })
        expect(view.container.ownerDocument.activeElement).toBe(root)
        second.setAttribute(
          unavailable,
          unavailable === 'aria-disabled' ? 'true' : '',
        )

        await view.update({ busy: false })
        expect(second.matches(':disabled')).toBe(false)
        expect(view.container.ownerDocument.activeElement).toBe(root)
      } finally {
        await view.unmount()
      }
    })
  }

  for (const choice of ['inside', 'outside', 'body'] as const) {
    it(`focus chosen after parking: preserves ${choice} on the reply`, async () => {
      const view = await mountModal()
      try {
        const second = find(view, 'busy-focus-second')
        const root = find(view, 'modal')
        const document = view.container.ownerDocument
        second.focus()
        await view.update({ busy: true })
        expect(document.activeElement).toBe(root)

        const target =
          choice === 'body'
            ? document.body
            : find(
                view,
                choice === 'inside'
                  ? 'busy-focus-inside-first'
                  : 'busy-focus-outside',
              )
        if (choice === 'body') root.blur()
        else target.focus()
        expect(document.activeElement).toBe(target)

        await view.update({ busy: false })
        expect(document.activeElement).toBe(target)
      } finally {
        await view.unmount()
      }
    })
  }

  it('focus chosen before busy: preserves an outside control throughout the cycle', async () => {
    const view = await mountModal()
    try {
      find(view, 'busy-focus-second').focus()
      const outside = find(view, 'busy-focus-outside')
      outside.focus()
      expect(view.container.ownerDocument.activeElement).toBe(outside)

      await view.update({ busy: true })
      expect(find(view, 'busy-focus-second').matches(':disabled')).toBe(true)
      expect(view.container.ownerDocument.activeElement).toBe(outside)
      await view.update({ busy: false })
      expect(view.container.ownerDocument.activeElement).toBe(outside)
    } finally {
      await view.unmount()
    }
  })

  it('submitting LoadingButton: retains focus and its own loading outside the field lock', async () => {
    let submitted = 0
    const view = await mountModal({
      body: 'custom',
      submitting: true,
      onSubmit: () => {
        submitted++
      },
    })
    try {
      const send = find<HTMLButtonElement>(view, 'busy-focus-submit')
      send.focus()
      send.click()
      await view.flush()
      expect(submitted).toBe(1)

      // Drive the unresolved request through the component's busy input.
      await view.update({ busy: true })
      expect(find(view, 'busy-focus-second').matches(':disabled')).toBe(true)
      expect(send.matches(':disabled')).toBe(false)
      expect(send.getAttribute('aria-busy')).toBe('true')
      expect(send.getAttribute('aria-disabled')).toBe('true')
      expect(view.container.ownerDocument.activeElement).toBe(send)
      send.click()
      await view.flush()
      expect(submitted).toBe(1)

      await view.update({ busy: false })
      expect(send.hasAttribute('aria-busy')).toBe(false)
      expect(send.matches(':disabled')).toBe(false)
      expect(view.container.ownerDocument.activeElement).toBe(send)
    } finally {
      await view.unmount()
    }
  })

  it('close and reopen: returns to the opener and discards the old busy target', async () => {
    const view = await mountModal({ open: false })
    try {
      const opener = find(view, 'busy-focus-opener')
      opener.focus()
      await view.update({ open: true })
      const oldSecond = find(view, 'busy-focus-second')
      oldSecond.focus()
      await view.update({ busy: true })
      expect(view.container.ownerDocument.activeElement).toBe(
        find(view, 'modal'),
      )

      await view.update({ open: false, busy: false })
      expect(view.container.querySelector('[data-id="modal"]')).toBeNull()
      expect(view.container.ownerDocument.activeElement).toBe(opener)
      expect(oldSecond.isConnected).toBe(false)

      await view.update({ open: true, busy: true })
      expect(find(view, 'busy-focus-second')).not.toBe(oldSecond)
      expect(view.container.ownerDocument.activeElement).toBe(
        find(view, 'modal'),
      )
      await view.update({ busy: false })
      expect(view.container.ownerDocument.activeElement).toBe(
        find(view, 'busy-focus-first'),
      )

      await view.update({ open: false })
      expect(view.container.ownerDocument.activeElement).toBe(opener)
    } finally {
      await view.unmount()
    }
  })

  for (const actions of [true, false]) {
    it(`Tab from a parked dialog: ${actions ? 'visits first and last available controls' : 'stays on the dialog without available controls'}`, async () => {
      const view = await mountModal({ actions })
      try {
        const second = find(view, 'busy-focus-second')
        const root = find(view, 'modal')
        second.focus()
        await view.update({ busy: true })
        expect(view.container.ownerDocument.activeElement).toBe(root)

        const tab = await view.key(root, 'Tab')
        expect(tab.defaultPrevented).toBe(true)
        expect(view.container.ownerDocument.activeElement).toBe(
          actions ? find(view, 'busy-focus-inside-first') : root,
        )
        root.focus()
        const shiftTab = await view.key(root, 'Tab', true)
        expect(shiftTab.defaultPrevented).toBe(true)
        expect(view.container.ownerDocument.activeElement).toBe(
          actions ? find(view, 'busy-focus-inside-last') : root,
        )

        await view.update({ busy: false })
        expect(view.container.ownerDocument.activeElement).toBe(
          actions ? find(view, 'busy-focus-inside-last') : second,
        )
      } finally {
        await view.unmount()
      }
    })
  }

  for (const initialFocus of ['', 'dialog', 'inner'] as const) {
    it(`step change: uses ${initialFocus || 'marked'} initial focus rather than a replacement with the captured data id`, async () => {
      const view = await mountModal({
        body: initialFocus === 'inner' ? 'custom' : 'default',
      })
      try {
        const oldSecond = find(view, 'busy-focus-second')
        const root = find(view, 'modal')
        oldSecond.focus()
        await view.update({ busy: true })
        expect(view.container.ownerDocument.activeElement).toBe(root)

        await view.update({ step: 'next', initialFocus })
        const replacement = find(view, 'busy-focus-second')
        expect(oldSecond.isConnected).toBe(false)
        expect(replacement).not.toBe(oldSecond)
        expect(replacement.hasAttribute('data-autofocus')).toBe(false)
        expect(view.container.ownerDocument.activeElement).toBe(root)

        await view.update({ busy: false })
        expect(view.container.ownerDocument.activeElement).toBe(
          initialFocus === 'dialog' ? root : find(view, 'busy-focus-first'),
        )
        expect(view.container.ownerDocument.activeElement).not.toBe(replacement)
      } finally {
        await view.unmount()
      }
    })
  }
}
