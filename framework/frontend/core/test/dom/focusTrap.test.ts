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

  it('uses only the nearest live surface fallback when the opener disappears', () => {
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
