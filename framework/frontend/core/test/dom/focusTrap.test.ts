// @vitest-environment jsdom
// jsdom, not happy-dom: a control inside a disabled fieldset matches
// `:disabled` only in jsdom, and that inheritance is what the trap reads.

import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
} from '../../src/connection/interactionBlock.js'
import {
  FocusTrap,
  focusableElements,
  focusInitial,
  registerFocusReturnFallback,
  type FocusPlacement,
} from '../../src/dom/focusTrap.js'

const traps: FocusTrap[] = []

afterEach(() => {
  for (const trap of traps) trap.release()
  traps.length = 0
  vi.restoreAllMocks()
  document.body.removeAttribute('tabindex')
  document.body.innerHTML = ''
})

/** Create a real trap whose document listener is released after the test. */
function createTrap(): FocusTrap {
  const trap = new FocusTrap()
  traps.push(trap)

  return trap
}

/**
 * A dialog root in the document, with `tabindex="-1"` so the root itself can
 * take focus the way `HilosModal` already does.
 *
 * @param html Optional inner markup.
 * @returns The mounted root.
 */
function mountRoot(html = ''): HTMLElement {
  const root = document.createElement('div')
  root.tabIndex = -1
  root.innerHTML = html
  document.body.append(root)

  return root
}

/**
 * Ask the trap to handle one Tab press from the element focused now.
 *
 * @param trap The trap under test.
 * @param root The dialog element whose focusables cycle.
 * @param shiftKey Whether Shift is held.
 * @returns Whether the trap prevented the browser's own move.
 */
function pressTab(
  trap: FocusTrap,
  root: HTMLElement,
  shiftKey = false,
): boolean {
  const event = new KeyboardEvent('keydown', {
    key: 'Tab',
    shiftKey,
    cancelable: true,
  })
  trap.handleTab(root, event)

  return event.defaultPrevented
}

/**
 * The `data-id`s of what `focusableElements` returns under `root`.
 *
 * @param root The dialog element to read.
 * @returns The ids in DOM order.
 */
function focusableIds(root: HTMLElement): (string | undefined)[] {
  return focusableElements(root).map((element) => element.dataset.id)
}

/**
 * Surfaces nested one inside the other in the document, each holding its own
 * fallback button, with the opener inside the innermost one.
 *
 * @param count How many surfaces wrap the opener.
 * @returns The surfaces and their buttons, outermost first, and the opener.
 */
function nestedSurfaces(count: number): {
  surfaces: HTMLElement[]
  targets: HTMLButtonElement[]
  opener: HTMLButtonElement
} {
  const surfaces: HTMLElement[] = []
  const targets: HTMLButtonElement[] = []
  let parent: HTMLElement = document.body
  for (let index = 0; index < count; index++) {
    const surface = document.createElement('section')
    const target = document.createElement('button')
    target.type = 'button'
    surface.append(target)
    parent.append(surface)
    surfaces.push(surface)
    targets.push(target)
    parent = surface
  }
  const opener = document.createElement('button')
  opener.type = 'button'
  parent.append(opener)

  return { surfaces, targets, opener }
}

/**
 * Open a trap on a fresh dialog from `opener`, the way a modal mount does.
 *
 * @param opener The control focused when the dialog opens.
 * @returns The dialog root and its active trap.
 */
function openFrom(opener: HTMLElement): {
  dialog: HTMLElement
  trap: FocusTrap
} {
  const dialog = mountRoot()
  const trap = createTrap()
  opener.focus()
  trap.activate(dialog)

  return { dialog, trap }
}

/**
 * A connected, visible button outside every surface: a fallback that hands it
 * out proves only that its own offer is still honored.
 *
 * @returns The stray button.
 */
function strayButton(): HTMLButtonElement {
  return mountRoot('<button type="button">Stray</button>').querySelector(
    'button',
  )!
}

/**
 * An open main dialog with two stable fields and independent body/footer buttons.
 * The second field deliberately has no autofocus mark.
 *
 * @param busy Whether the body is disabled before activation.
 * @param placement The dialog's initial and idle-fallback focus policy.
 * @returns The real trap and its current mounted DOM handles.
 */
function mountBusyDialog(busy = false, placement: FocusPlacement = 'marked') {
  const root = mountRoot(
    '<section data-id="body">' +
      '<fieldset><input data-autofocus data-id="first" />' +
      '<input data-id="second" value="Draft" /></fieldset>' +
      '<button type="button" data-id="loading">Save</button></section>' +
      '<button type="button" data-id="footer">Cancel</button>',
  )
  root.setAttribute('role', 'dialog')
  root.setAttribute(HILOS_INTERACTION_REGION_ATTR, '')
  const body = root.querySelector<HTMLElement>('[data-id="body"]')!
  const fieldset = body.querySelector('fieldset')!
  const first = body.querySelector<HTMLInputElement>('[data-id="first"]')!
  const second = body.querySelector<HTMLInputElement>('[data-id="second"]')!
  const loading = body.querySelector<HTMLButtonElement>('[data-id="loading"]')!
  const footer = root.querySelector<HTMLButtonElement>('[data-id="footer"]')!
  fieldset.disabled = busy
  const trap = createTrap()
  trap.activate(root, placement)
  trap.setBusy(root, body, busy, placement)

  return { root, body, fieldset, first, second, loading, footer, trap }
}

describe('focusableElements', () => {
  it('skips the controls a disabled fieldset turns off, but not its first legend or a link', () => {
    const root = mountRoot(
      '<button type="button" data-id="close">Close</button>' +
        '<fieldset disabled>' +
        '<legend><button type="button" data-id="help">Help</button></legend>' +
        '<input data-id="field" />' +
        '<input tabindex="0" data-id="indexed" />' +
        '<select data-id="choice"></select>' +
        '<textarea data-id="text"></textarea>' +
        '<a href="#" data-id="link">Link</a>' +
        '<button type="button" data-id="save">Save</button>' +
        '</fieldset>',
    )
    expect(focusableIds(root)).toEqual(['close', 'help', 'link'])
  })

  it('returns the controls once their fieldset is enabled again', () => {
    const root = mountRoot(
      '<button type="button" data-id="close">Close</button>' +
        '<fieldset disabled>' +
        '<input data-id="field" />' +
        '<button type="button" data-id="save">Save</button>' +
        '</fieldset>',
    )
    expect(focusableIds(root)).toEqual(['close'])
    root.querySelector('fieldset')!.disabled = false
    expect(focusableIds(root)).toEqual(['close', 'field', 'save'])
  })
})

describe('focusInitial', () => {
  it('focuses the marked element when a mark is present', () => {
    const root = mountRoot(
      '<button type="button">Close</button><input data-autofocus data-id="field" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="field"]'))
  })

  it('focuses the root when there is no mark, even if something is focusable', () => {
    const root = mountRoot('<button type="button">Close</button>')
    focusInitial(root)
    expect(document.activeElement).toBe(root)
  })

  it('focuses the root when there is no mark and nothing is focusable', () => {
    const root = mountRoot()
    focusInitial(root)
    expect(document.activeElement).toBe(root)
  })

  it('focuses the root under dialog even when a mark is present', () => {
    const root = mountRoot('<input data-autofocus data-id="field" />')
    focusInitial(root, 'dialog')
    expect(document.activeElement).toBe(root)
  })

  it('focuses the checked radio when three sibling radios are marked', () => {
    const root = mountRoot(
      '<input type="radio" name="group" value="one" data-autofocus data-id="r1" />' +
        '<input type="radio" name="group" value="two" checked data-autofocus data-id="r2" />' +
        '<input type="radio" name="group" value="three" data-autofocus data-id="r3" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="r2"]'))
  })

  it('focuses the third radio when it is the checked one in the marked group', () => {
    const root = mountRoot(
      '<input type="radio" name="group" value="one" data-autofocus data-id="r1" />' +
        '<input type="radio" name="group" value="two" data-autofocus data-id="r2" />' +
        '<input type="radio" name="group" value="three" checked data-autofocus data-id="r3" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="r3"]'))
  })

  it('focuses the first marked radio when no radio in the group is checked', () => {
    const root = mountRoot(
      '<input type="radio" name="group" value="one" data-autofocus data-id="r1" />' +
        '<input type="radio" name="group" value="two" data-autofocus data-id="r2" />' +
        '<input type="radio" name="group" value="three" data-autofocus data-id="r3" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="r1"]'))
  })

  it('focuses the first marked radio when the checked radio is disabled', () => {
    const root = mountRoot(
      '<input type="radio" name="group" value="one" data-autofocus data-id="r1" />' +
        '<input type="radio" name="group" value="two" checked disabled data-autofocus data-id="r2" />' +
        '<input type="radio" name="group" value="three" data-autofocus data-id="r3" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="r1"]'))
  })

  it('focuses the marked radio when the checked radio belongs to another group', () => {
    const root = mountRoot(
      '<input type="radio" name="groupA" value="a1" data-autofocus data-id="rA" />' +
        '<input type="radio" name="groupB" value="b1" checked data-id="rB" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="rA"]'))
  })

  it('focuses the marked non-radio field even when a checked radio follows', () => {
    const root = mountRoot(
      '<input type="text" data-autofocus data-id="field" />' +
        '<input type="radio" name="group" value="r1" checked data-id="radio" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="field"]'))
  })

  it('focuses the marked radio when its fieldset disables the checked one', () => {
    const root = mountRoot(
      '<input type="radio" name="group" value="one" data-autofocus data-id="r1" />' +
        '<fieldset disabled>' +
        '<input type="radio" name="group" value="two" checked data-id="r2" />' +
        '</fieldset>',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="r1"]'))
  })

  it('focuses the root when the only mark sits in a disabled fieldset', () => {
    const root = mountRoot(
      '<button type="button">Close</button>' +
        '<fieldset disabled><input data-autofocus data-id="field" /></fieldset>',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root)
  })

  it('focuses the first mark that is not disabled', () => {
    const root = mountRoot(
      '<fieldset disabled><input data-autofocus data-id="locked" /></fieldset>' +
        '<input data-autofocus data-id="open" />',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="open"]'))
  })

  it('focuses the mark again once its fieldset is enabled', () => {
    const root = mountRoot(
      '<fieldset disabled><input data-autofocus data-id="field" /></fieldset>',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root)
    root.querySelector('fieldset')!.disabled = false
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="field"]'))
  })
})

describe('FocusTrap', () => {
  it.each([false, true])(
    'takes Tab from the dialog to its boundary control (shift: %s)',
    (shiftKey) => {
      const root = mountRoot(
        '<button type="button" data-id="first">First</button>' +
          '<input disabled />' +
          '<button type="button" data-id="last">Last</button>',
      )
      const trap = createTrap()
      trap.activate(root)
      expect(document.activeElement).toBe(root)
      expect(pressTab(trap, root, shiftKey)).toBe(true)
      expect(document.activeElement).toBe(
        root.querySelector(shiftKey ? '[data-id="last"]' : '[data-id="first"]'),
      )
      trap.release()
    },
  )

  it('wraps Tab over the controls a disabled fieldset turns off', () => {
    const root = mountRoot(
      '<fieldset disabled><input data-id="before" /></fieldset>' +
        '<button type="button" data-id="first">First</button>' +
        '<button type="button" data-id="last">Last</button>' +
        '<fieldset disabled><input data-id="after" /></fieldset>',
    )
    const first = root.querySelector<HTMLElement>('[data-id="first"]')!
    const last = root.querySelector<HTMLElement>('[data-id="last"]')!
    const trap = createTrap()
    trap.activate(root)
    last.focus()
    expect(pressTab(trap, root)).toBe(true)
    expect(document.activeElement).toBe(first)
    expect(pressTab(trap, root, true)).toBe(true)
    expect(document.activeElement).toBe(last)
  })

  it('keeps Tab on the dialog when every control inside is disabled', () => {
    const root = mountRoot(
      '<fieldset disabled>' +
        '<input data-autofocus />' +
        '<button type="button">Save</button>' +
        '</fieldset>',
    )
    const trap = createTrap()
    trap.activate(root)
    expect(document.activeElement).toBe(root)
    expect(pressTab(trap, root)).toBe(true)
    expect(document.activeElement).toBe(root)
    expect(pressTab(trap, root, true)).toBe(true)
    expect(document.activeElement).toBe(root)
  })

  it('returns focus to the opener on release', () => {
    const opener = document.createElement('button')
    opener.type = 'button'
    document.body.append(opener)
    opener.focus()
    const root = mountRoot('<button type="button">Inside</button>')
    const trap = createTrap()
    trap.activate(root)
    trap.release()
    expect(document.activeElement).toBe(opener)
  })

  it('prefers the nearest live surface fallback when the opener disappears', () => {
    const outer = mountRoot('<button>Outer fallback</button>')
    const inner = document.createElement('section')
    inner.innerHTML = '<input /><button>Open</button>'
    outer.append(inner)
    const fallback = inner.querySelector('input')!
    const opener = inner.querySelector('button')!
    const disposeOuter = registerFocusReturnFallback(outer, () => outer)
    const disposeInner = registerFocusReturnFallback(inner, () => fallback)
    const root = mountRoot()
    opener.focus()
    const trap = createTrap()
    trap.activate(root)
    opener.remove()
    expect(document.activeElement).toBe(root)
    trap.release()
    expect(document.activeElement).toBe(fallback)
    disposeInner()
    disposeOuter()
  })

  it.each(['hidden', 'inert', 'disabled'] as const)(
    'rejects a connected %s opener without redirecting to a matching control',
    (state) => {
      const surface = mountRoot('<input /><button data-id="same">Open</button>')
      const opener = surface.querySelector('button')!
      const fallback = surface.querySelector('input')!
      const dispose = registerFocusReturnFallback(surface, () => fallback)
      const root = mountRoot()
      const trap = createTrap()
      opener.focus()
      trap.activate(root)
      opener.setAttribute(state, '')
      const unrelated = mountRoot('<button data-id="same">Other</button>')
      trap.release()
      expect(document.activeElement).toBe(fallback)
      expect(document.activeElement).not.toBe(unrelated.querySelector('button'))
      dispose()
    },
  )

  it('invalidates an already captured fallback on disposal and forgets it on release', () => {
    const surface = mountRoot('<button>Open</button><input />')
    const opener = surface.querySelector('button')!
    const fallback = surface.querySelector('input')!
    const dispose = registerFocusReturnFallback(surface, () => fallback)
    const root = mountRoot()
    const trap = createTrap()
    opener.focus()
    trap.activate(root)
    opener.remove()
    dispose()
    trap.release()
    expect(document.activeElement).toBe(root)
    fallback.focus()
    trap.release()
    expect(document.activeElement).toBe(fallback)
  })

  it('preserves another control chosen while open and returns through nested dialogs', () => {
    const opener = mountRoot('<button>Open</button>').querySelector('button')!
    const root = mountRoot('<button>Details</button>')
    const upper = mountRoot()
    const trap = createTrap()
    const nested = createTrap()
    opener.focus()
    trap.activate(root)
    const details = root.querySelector('button')!
    details.focus()
    nested.activate(upper)
    nested.release()
    expect(document.activeElement).toBe(details)
    trap.release()
    expect(document.activeElement).toBe(opener)
    trap.activate(root)
    const other = mountRoot('<input />').querySelector('input')!
    other.focus()
    trap.release()
    expect(document.activeElement).toBe(other)
  })
})

describe('FocusTrap busy focus', () => {
  it.each(['control', 'fieldset'] as const)(
    'parks a focused field disabled by its %s and restores the same unmarked node and value',
    (source) => {
      const { root, body, fieldset, first, second, trap } = mountBusyDialog()
      expect(document.activeElement).toBe(first)
      second.focus()
      second.value = 'Edited second field'
      const park = vi.spyOn(root, 'focus')
      const restore = vi.spyOn(second, 'focus')
      if (source === 'control') second.disabled = true
      else fieldset.disabled = true
      // jsdom leaves activeElement on the disabled field. This is one possible
      // post-render state, not proof of native browser focus fixup.
      expect(document.activeElement).toBe(second)
      trap.setBusy(root, body, true)
      expect(document.activeElement).toBe(root)
      expect(park).toHaveBeenCalledExactlyOnceWith({ preventScroll: true })
      second.disabled = false
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(second)
      expect(body.querySelector('[data-id="second"]')).toBe(second)
      expect(second.value).toBe('Edited second field')
      expect(restore).toHaveBeenCalledExactlyOnceWith({ preventScroll: true })
    },
  )

  it.each(['without focusin', 'with focusin'] as const)(
    'keeps the pre-disabled snapshot when activeElement is already body %s',
    (fixup) => {
      const { root, body, fieldset, second, trap } = mountBusyDialog()
      second.focus()
      fieldset.disabled = true
      // Explicit simulation: jsdom does not perform the browser's disabled fixup.
      const active =
        fixup === 'without focusin'
          ? vi
              .spyOn(document, 'activeElement', 'get')
              .mockReturnValue(document.body)
          : undefined
      if (fixup === 'with focusin') {
        document.body.tabIndex = -1
        document.body.focus()
      }
      expect(document.activeElement).toBe(document.body)
      trap.setBusy(root, body, true)
      active?.mockRestore()
      expect(document.activeElement).toBe(root)
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(second)
    },
  )

  it('observes initial autofocus before disabled can drop it to body', () => {
    const { root, body, fieldset, first, trap } = mountBusyDialog()
    expect(document.activeElement).toBe(first)
    fieldset.disabled = true
    const active = vi
      .spyOn(document, 'activeElement', 'get')
      .mockReturnValue(document.body)
    trap.setBusy(root, body, true)
    active.mockRestore()
    expect(document.activeElement).toBe(root)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(first)
  })

  it.each([
    'disabled',
    'fieldset',
    'hidden',
    'inert',
    'aria-disabled',
    'display',
    'visibility',
    'collapse',
    'connection',
  ] as const)(
    'never falls back around a surviving %s target or retries on an idle render',
    (reason) => {
      const { root, body, fieldset, second, trap } = mountBusyDialog()
      const sheet = document.createElement('style')
      sheet.textContent = `
        .d-none { display: none !important; }
        .invisible { visibility: hidden !important; }
        .test-collapse { visibility: collapse; }
      `
      root.append(sheet)
      second.focus()
      fieldset.disabled = true
      trap.setBusy(root, body, true)
      expect(document.activeElement).toBe(root)
      fieldset.disabled = false
      switch (reason) {
        case 'disabled':
          second.disabled = true
          break
        case 'fieldset':
          fieldset.disabled = true
          break
        case 'hidden':
        case 'inert':
          fieldset.setAttribute(reason, '')
          break
        case 'aria-disabled':
          second.setAttribute('aria-disabled', 'true')
          break
        case 'display':
          fieldset.classList.add('d-none')
          break
        case 'visibility':
          fieldset.classList.add('invisible')
          break
        case 'collapse':
          fieldset.classList.add('test-collapse')
          break
        case 'connection':
          root.setAttribute(HILOS_INTERACTION_BLOCKED_ATTR, '')
          break
      }
      const restore = vi.spyOn(second, 'focus')
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(root)
      expect(restore).not.toHaveBeenCalled()
      second.disabled = false
      fieldset.disabled = false
      fieldset.removeAttribute('hidden')
      fieldset.removeAttribute('inert')
      fieldset.removeAttribute('class')
      second.removeAttribute('aria-disabled')
      root.removeAttribute(HILOS_INTERACTION_BLOCKED_ATTR)
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(root)
      expect(restore).not.toHaveBeenCalled()
    },
  )

  it.each(['footer', 'loading', 'outside'] as const)(
    'preserves another %s control chosen before busy',
    (choice) => {
      const { root, body, fieldset, second, footer, loading, trap } =
        mountBusyDialog()
      second.focus()
      const other =
        choice === 'footer'
          ? footer
          : choice === 'loading'
            ? loading
            : strayButton()
      other.focus()
      if (choice === 'loading') other.setAttribute('aria-disabled', 'true')
      fieldset.disabled = true
      const park = vi.spyOn(root, 'focus')
      trap.setBusy(root, body, true)
      expect(document.activeElement).toBe(other)
      expect(park).not.toHaveBeenCalled()
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(other)
    },
  )

  it('forgets an old field when another control was chosen and then focus left for body', () => {
    const { root, body, fieldset, first, second, trap } = mountBusyDialog()
    second.focus()
    const other = strayButton()
    other.focus()
    fieldset.disabled = true
    other.blur()
    expect(document.activeElement).toBe(document.body)
    trap.setBusy(root, body, true)
    expect(document.activeElement).toBe(document.body)
    root.focus()
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(first)
  })

  it.each(['footer', 'outside', 'upper dialog', 'body'] as const)(
    'preserves %s focus chosen after parking and clears the cycle',
    (choice) => {
      const { root, body, fieldset, second, footer, trap } = mountBusyDialog()
      second.focus()
      fieldset.disabled = true
      trap.setBusy(root, body, true)
      expect(document.activeElement).toBe(root)
      let other: HTMLElement
      if (choice === 'body') {
        root.blur()
        other = document.body
      } else if (choice === 'upper dialog') {
        other = mountRoot()
        other.setAttribute('role', 'dialog')
        other.focus()
      } else {
        other = choice === 'footer' ? footer : strayButton()
        other.focus()
      }
      fieldset.disabled = false
      const restore = vi.spyOn(second, 'focus')
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(other)
      expect(restore).not.toHaveBeenCalled()
      root.focus()
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(root)
      expect(restore).not.toHaveBeenCalled()
    },
  )

  it('captures once per busy cycle and follows the next cycle independently', () => {
    const { root, body, fieldset, first, second, footer, trap } =
      mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    trap.refocus(root, 'dialog')
    trap.setBusy(root, body, true)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(second)
    footer.focus()
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(footer)
    first.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    expect(document.activeElement).toBe(root)
    trap.setBusy(root, body, true)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(first)
  })

  it.each(['already busy', 'root before busy'] as const)(
    'keeps the idle fallback when the dialog is %s without a captured field',
    (start) => {
      const { root, body, fieldset, first, trap } = mountBusyDialog(
        start === 'already busy',
      )
      if (start === 'root before busy') {
        root.focus()
        fieldset.disabled = true
        trap.setBusy(root, body, true)
      }
      expect(document.activeElement).toBe(root)
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(first)
    },
  )

  it.each(['marked', 'dialog'] as const)(
    'uses %s placement for a new step after the captured node leaves the body',
    (placement) => {
      const { root, body, fieldset, second, trap } = mountBusyDialog()
      second.focus()
      fieldset.disabled = true
      trap.setBusy(root, body, true)
      body.innerHTML =
        '<input data-id="second" value="Lookalike" />' +
        '<input data-autofocus data-id="new-step" />'
      const next = body.querySelector('[data-id="new-step"]')
      trap.setBusy(root, body, false, placement)
      expect(document.activeElement).toBe(placement === 'marked' ? next : root)
    },
  )

  it('allows the uncaptured fallback from body but preserves another chosen control', () => {
    const { root, body, fieldset, first, footer, trap } = mountBusyDialog(true)
    root.blur()
    expect(document.activeElement).toBe(document.body)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(first)
    root.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    footer.focus()
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(footer)
  })

  it('does not focus a new step over an explicit choice made after parking', () => {
    const { root, body, fieldset, second, footer, trap } = mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    body.innerHTML = '<input data-autofocus />'
    footer.focus()
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(footer)
  })

  it('clears a busy capture before returning to the opener and reopening', () => {
    const opener = strayButton()
    opener.focus()
    const { root, body, fieldset, first, second, trap } = mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    trap.release()
    expect(document.activeElement).toBe(opener)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(opener)
    trap.activate(root, 'dialog')
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(root)
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(first)
  })

  it('replaces activation listeners and removes them exactly once on repeated release', () => {
    const add = vi.spyOn(document, 'addEventListener')
    const remove = vi.spyOn(document, 'removeEventListener')
    const { root, body, fieldset, second, trap } = mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    fieldset.disabled = false
    trap.activate(root, 'dialog')
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(root)
    trap.release()
    trap.release()
    const subscriptions = add.mock.calls.filter(([type]) => type === 'focusin')
    const removals = remove.mock.calls.filter(([type]) => type === 'focusin')
    expect(subscriptions).toHaveLength(2)
    expect(removals).toHaveLength(2)
    for (const [, listener] of subscriptions) {
      expect(remove).toHaveBeenCalledWith('focusin', listener, true)
    }
  })

  it.each(['dialog', 'alertdialog'] as const)(
    'invalidates the old capture when refocus switches to another %s root',
    (role) => {
      const { root, body, fieldset, first, second, trap } = mountBusyDialog()
      second.focus()
      fieldset.disabled = true
      trap.setBusy(root, body, true)
      const upper = mountRoot('<button>Confirm</button>')
      upper.setAttribute('role', role)
      // Angular renders its confirm inside the main dialog.
      root.append(upper)
      trap.refocus(upper, 'dialog')
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(upper)
      trap.refocus(root, 'dialog')
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(root)
      fieldset.disabled = true
      trap.setBusy(root, body, true)
      fieldset.disabled = false
      trap.setBusy(root, body, false)
      expect(document.activeElement).toBe(first)
    },
  )

  it.each(['dialog', 'alertdialog', 'interaction region'] as const)(
    'does not capture a disabled field of a nested %s even after focus fixup to body',
    (boundary) => {
      const { root, body, trap } = mountBusyDialog()
      const nested = mountRoot('<input />')
      if (boundary === 'interaction region')
        nested.setAttribute(HILOS_INTERACTION_REGION_ATTR, '')
      else nested.setAttribute('role', boundary)
      body.append(nested)
      const field = nested.querySelector('input')!
      field.focus()
      field.disabled = true
      document.body.tabIndex = -1
      document.body.focus()
      expect(document.activeElement).toBe(document.body)
      trap.setBusy(root, body, true)
      expect(document.activeElement).toBe(document.body)
    },
  )

  it('rejects a captured node moved into a nested dialog without initial-focus fallback', () => {
    const { root, body, fieldset, second, trap } = mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    trap.setBusy(root, body, true)
    const nested = document.createElement('section')
    nested.setAttribute('role', 'alertdialog')
    body.append(nested)
    nested.append(second)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(root)
  })

  it('ignores another root and a foreign body without consuming the busy transition', () => {
    const { root, body, fieldset, second, trap } = mountBusyDialog()
    const otherRoot = mountRoot()
    const foreignBody = document.createElement('section')
    foreignBody.setAttribute(HILOS_INTERACTION_REGION_ATTR, '')
    body.append(foreignBody)
    second.focus()
    fieldset.disabled = true
    trap.setBusy(otherRoot, otherRoot, true)
    trap.setBusy(root, foreignBody, true)
    expect(document.activeElement).toBe(second)
    trap.setBusy(root, body, true)
    expect(document.activeElement).toBe(root)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(second)
  })

  it('does not park or restore into a disconnected root', () => {
    const { root, body, fieldset, second, trap } = mountBusyDialog()
    second.focus()
    fieldset.disabled = true
    root.remove()
    const park = vi.spyOn(root, 'focus')
    trap.setBusy(root, body, true)
    fieldset.disabled = false
    trap.setBusy(root, body, false)
    expect(document.activeElement).toBe(document.body)
    expect(park).not.toHaveBeenCalled()
  })
})

describe('registerFocusReturnFallback around nested surfaces', () => {
  it('returns to an available opener before any surface fallback', () => {
    const { surfaces, targets, opener } = nestedSurfaces(2)
    registerFocusReturnFallback(surfaces[0], () => targets[0])
    registerFocusReturnFallback(surfaces[1], () => targets[1])
    const { trap } = openFrom(opener)
    trap.release()
    expect(document.activeElement).toBe(opener)
  })

  it('keeps the nearest available target over an available outer one', () => {
    const { surfaces, targets, opener } = nestedSurfaces(2)
    registerFocusReturnFallback(surfaces[0], () => targets[0])
    registerFocusReturnFallback(surfaces[1], () => targets[1])
    const { trap } = openFrom(opener)
    opener.remove()
    trap.release()
    expect(document.activeElement).toBe(targets[1])
  })

  it.each(['withdrawn', 'detached'] as const)(
    'asks the outer surface once the nearer one is %s',
    (loss) => {
      const { surfaces, targets, opener } = nestedSurfaces(2)
      const stray = strayButton()
      registerFocusReturnFallback(surfaces[0], () => targets[0])
      const disposeNearer = registerFocusReturnFallback(
        surfaces[1],
        () => stray,
      )
      const { trap } = openFrom(opener)
      if (loss === 'withdrawn') {
        opener.remove()
        disposeNearer()
      } else {
        surfaces[1].remove()
      }
      trap.release()
      expect(document.activeElement).toBe(targets[0])
    },
  )

  it.each(['null', 'hidden', 'inert', 'disabled'] as const)(
    'asks the outer surface when the nearer target is %s',
    (state) => {
      const { surfaces, targets, opener } = nestedSurfaces(2)
      registerFocusReturnFallback(surfaces[0], () => targets[0])
      registerFocusReturnFallback(surfaces[1], () =>
        state === 'null' ? null : targets[1],
      )
      if (state !== 'null') targets[1].setAttribute(state, '')
      const { trap } = openFrom(opener)
      opener.remove()
      trap.release()
      expect(document.activeElement).toBe(targets[0])
    },
  )

  it('walks out to the outermost surface that still gives a target', () => {
    const { surfaces, targets, opener } = nestedSurfaces(3)
    registerFocusReturnFallback(surfaces[0], () => targets[0])
    const disposeMiddle = registerFocusReturnFallback(
      surfaces[1],
      () => targets[1],
    )
    registerFocusReturnFallback(surfaces[2], () => null)
    const { trap } = openFrom(opener)
    opener.remove()
    disposeMiddle()
    trap.release()
    expect(document.activeElement).toBe(targets[0])
  })

  it.each(['withdrawn', 'detached'] as const)(
    'leaves focus in the dialog when the outer surface is %s after capture too',
    (loss) => {
      const { surfaces, opener } = nestedSurfaces(2)
      const stray = strayButton()
      const disposeOuter = registerFocusReturnFallback(surfaces[0], () => stray)
      const disposeNearer = registerFocusReturnFallback(
        surfaces[1],
        () => stray,
      )
      const { dialog, trap } = openFrom(opener)
      opener.remove()
      disposeNearer()
      if (loss === 'withdrawn') {
        disposeOuter()
      } else {
        surfaces[0].remove()
      }
      trap.release()
      expect(document.activeElement).toBe(dialog)
    },
  )
})

describe('registerFocusReturnFallback replacement capture', () => {
  it('captures the request source while mounted, before dialog focus, and prefers the original opener', () => {
    const { surfaces, targets, opener } = nestedSurfaces(1)
    const replacement = strayButton()
    const readReplacement = vi.fn(() => replacement)
    const fallback = vi.fn(() => targets[0])
    const capture = vi.fn((source: HTMLElement) => {
      expect(source).toBe(opener)
      expect(source.isConnected).toBe(true)
      expect(document.activeElement).toBe(opener)

      return readReplacement
    })
    registerFocusReturnFallback(surfaces[0], fallback, capture)
    const { trap } = openFrom(opener)
    expect(capture).toHaveBeenCalledExactlyOnceWith(opener)
    trap.release()
    expect(document.activeElement).toBe(opener)
    expect(readReplacement).not.toHaveBeenCalled()
    expect(fallback).not.toHaveBeenCalled()
  })

  it('uses the request source even if focus changes while the request bubbles', () => {
    const { surfaces, targets, opener } = nestedSurfaces(1)
    const replacement = strayButton()
    opener.addEventListener('hilos-focus-return-request', () => {
      targets[0].focus()
    })
    const capture = vi.fn((source: HTMLElement) => {
      expect(document.activeElement).toBe(targets[0])

      return source === opener ? () => replacement : null
    })
    registerFocusReturnFallback(surfaces[0], () => targets[0], capture)
    const { trap } = openFrom(opener)
    expect(capture).toHaveBeenCalledExactlyOnceWith(opener)
    opener.remove()
    trap.release()
    expect(document.activeElement).toBe(replacement)
  })

  it.each(['search', 'root'] as const)(
    'prefers an available replacement over the ordinary %s fallback and forgets it on release',
    (kind) => {
      const { surfaces, targets, opener } = nestedSurfaces(1)
      surfaces[0].tabIndex = -1
      const replacement = strayButton()
      const readReplacement = vi.fn(() => replacement)
      const fallback = vi.fn(() => (kind === 'root' ? surfaces[0] : targets[0]))
      registerFocusReturnFallback(surfaces[0], fallback, () => readReplacement)
      const { trap, dialog } = openFrom(opener)
      opener.remove()
      trap.release()
      expect(document.activeElement).toBe(replacement)
      expect(readReplacement).toHaveBeenCalledOnce()
      expect(fallback).not.toHaveBeenCalled()
      dialog.focus()
      trap.release()
      expect(document.activeElement).toBe(dialog)
      expect(readReplacement).toHaveBeenCalledOnce()
    },
  )

  it.each(['null', 'hidden', 'inert', 'disabled', 'detached'] as const)(
    'uses the ordinary fallback when replacement is %s',
    (state) => {
      const { surfaces, targets, opener } = nestedSurfaces(1)
      const replacement = strayButton()
      registerFocusReturnFallback(
        surfaces[0],
        () => targets[0],
        () => () => (state === 'null' ? null : replacement),
      )
      const { trap } = openFrom(opener)
      opener.remove()
      if (state === 'detached') replacement.remove()
      else if (state !== 'null') replacement.setAttribute(state, '')
      trap.release()
      expect(document.activeElement).toBe(targets[0])
    },
  )

  it('keeps a nearer ordinary fallback ahead of an outer replacement', () => {
    const { surfaces, targets, opener } = nestedSurfaces(2)
    const outerReplacement = strayButton()
    const readOuter = vi.fn(() => outerReplacement)
    registerFocusReturnFallback(
      surfaces[0],
      () => targets[0],
      () => readOuter,
    )
    registerFocusReturnFallback(
      surfaces[1],
      () => targets[1],
      () => null,
    )
    const { trap } = openFrom(opener)
    opener.remove()
    trap.release()
    expect(document.activeElement).toBe(targets[1])
    expect(readOuter).not.toHaveBeenCalled()
  })

  it('tries an outer replacement when the nearer replacement and fallback are unavailable', () => {
    const { surfaces, targets, opener } = nestedSurfaces(2)
    const replacement = strayButton()
    registerFocusReturnFallback(
      surfaces[0],
      () => targets[0],
      () => () => replacement,
    )
    targets[1].hidden = true
    registerFocusReturnFallback(
      surfaces[1],
      () => targets[1],
      () => () => targets[1],
    )
    const { trap } = openFrom(opener)
    opener.remove()
    trap.release()
    expect(document.activeElement).toBe(replacement)
  })

  it.each(['withdrawn', 'detached'] as const)(
    'invalidates an already captured replacement when its owner is %s',
    (loss) => {
      const { surfaces, targets, opener } = nestedSurfaces(2)
      const replacement = strayButton()
      const readReplacement = vi.fn(() => replacement)
      const fallback = vi.fn(() => replacement)
      registerFocusReturnFallback(surfaces[0], () => targets[0])
      const dispose = registerFocusReturnFallback(
        surfaces[1],
        fallback,
        () => readReplacement,
      )
      const { trap } = openFrom(opener)
      opener.remove()
      if (loss === 'withdrawn') dispose()
      else surfaces[1].remove()
      trap.release()
      expect(document.activeElement).toBe(targets[0])
      expect(readReplacement).not.toHaveBeenCalled()
      expect(fallback).not.toHaveBeenCalled()
    },
  )

  it('keeps two captures from one registration independent', () => {
    const { surfaces, targets, opener } = nestedSurfaces(1)
    const secondOpener = document.createElement('button')
    surfaces[0].append(secondOpener)
    const firstReplacement = strayButton()
    const secondReplacement = strayButton()
    registerFocusReturnFallback(
      surfaces[0],
      () => targets[0],
      (source) =>
        source === opener ? () => firstReplacement : () => secondReplacement,
    )
    const first = openFrom(opener)
    const second = openFrom(secondOpener)
    opener.remove()
    secondOpener.remove()
    second.trap.release()
    expect(document.activeElement).toBe(secondReplacement)
    first.dialog.focus()
    first.trap.release()
    expect(document.activeElement).toBe(firstReplacement)
  })

  it('preserves upper-dialog and explicit outside focus without reading a replacement', () => {
    const { surfaces, targets, opener } = nestedSurfaces(1)
    const replacement = strayButton()
    const readReplacement = vi.fn(() => replacement)
    registerFocusReturnFallback(
      surfaces[0],
      () => targets[0],
      () => readReplacement,
    )
    const lower = openFrom(opener)
    const upper = openFrom(lower.dialog)
    opener.remove()
    lower.trap.release()
    expect(document.activeElement).toBe(upper.dialog)
    expect(readReplacement).not.toHaveBeenCalled()
    upper.trap.release()
    expect(document.activeElement).toBe(lower.dialog)

    const next = openFrom(targets[0])
    targets[0].remove()
    const outside = strayButton()
    outside.focus()
    next.trap.release()
    expect(document.activeElement).toBe(outside)
    expect(readReplacement).not.toHaveBeenCalled()
  })
})

describe('connection interaction availability', () => {
  it('excludes blocked links, roles, marks and roots from tab and initial focus', () => {
    const root = mountRoot(
      '<section data-hilos-interaction-region data-hilos-interaction-blocked tabindex="-1" data-id="region">' +
        '<a href="/next" data-autofocus data-id="link">Next</a>' +
        '<div role="button" tabindex="0" data-id="role">Action</div>' +
        '<input data-id="input">' +
        '</section>' +
        '<a href="/own" aria-disabled="true" data-autofocus data-id="own">Own</a>' +
        '<input data-autofocus data-id="ready">',
    )
    expect(focusableIds(root)).toEqual(['ready'])
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-id="ready"]'))
  })

  it('skips a disabled checked radio in favor of the available marked radio', () => {
    const root = mountRoot(
      '<input type="radio" name="group" data-autofocus>' +
        '<section data-hilos-interaction-region data-hilos-interaction-blocked>' +
        '<input type="radio" name="group" checked>' +
        '</section>',
    )
    focusInitial(root)
    expect(document.activeElement).toBe(root.querySelector('[data-autofocus]'))
  })

  it.each([
    '<a href="/next" aria-disabled="true">Opener</a>',
    '<div role="button" tabindex="0" aria-disabled="true">Opener</div>',
    '<main data-hilos-interaction-region data-hilos-interaction-blocked tabindex="-1">Opener</main>',
  ])(
    'returns to the nearest available fallback when the original target is unavailable: %s',
    (html) => {
      const surface = mountRoot(html + '<button>Fallback</button>')
      const opener = surface.firstElementChild as HTMLElement
      const fallback = surface.querySelector('button')!
      const unregister = registerFocusReturnFallback(surface, () => fallback)
      const { trap } = openFrom(opener)
      trap.release()
      expect(document.activeElement).toBe(fallback)
      unregister()
    },
  )

  it('never substitutes an unmarked lookalike for the unavailable exact opener', () => {
    const surface = mountRoot(
      '<a href="/next">Opener</a><button>Fallback</button>',
    )
    const original = surface.querySelector('a')!
    const fallback = surface.querySelector('button')!
    const unregister = registerFocusReturnFallback(surface, () => fallback)
    const { trap } = openFrom(original)
    original.setAttribute('aria-disabled', 'true')
    const lookalike = document.createElement('a')
    lookalike.href = '/next'
    lookalike.textContent = 'Opener'
    original.after(lookalike)
    trap.release()
    expect(document.activeElement).toBe(fallback)
    unregister()
  })

  it('preserves an explicit focus choice even when the opener is connection-blocked', () => {
    const openerRegion = mountRoot('<a href="/next">Opener</a>')
    const { trap } = openFrom(openerRegion.querySelector('a')!)
    openerRegion.setAttribute('data-hilos-interaction-region', '')
    openerRegion.setAttribute('data-hilos-interaction-blocked', '')
    const outside = strayButton()
    outside.focus()
    trap.release()
    expect(document.activeElement).toBe(outside)
  })
})
