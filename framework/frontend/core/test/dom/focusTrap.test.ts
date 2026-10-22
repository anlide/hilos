// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import {
  FocusTrap,
  focusInitial,
  registerFocusReturnFallback,
} from '../../src/dom/focusTrap.js'

afterEach(() => {
  document.body.innerHTML = ''
})

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
  const trap = new FocusTrap()
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
})

describe('FocusTrap', () => {
  it('returns focus to the opener on release', () => {
    const opener = document.createElement('button')
    opener.type = 'button'
    document.body.append(opener)
    opener.focus()
    const root = mountRoot('<button type="button">Inside</button>')
    const trap = new FocusTrap()
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
    const trap = new FocusTrap()
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
      const trap = new FocusTrap()
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
    const trap = new FocusTrap()
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
    const trap = new FocusTrap()
    const nested = new FocusTrap()
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
