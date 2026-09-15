// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import { FocusTrap, focusInitial } from '../../src/dom/focusTrap.js'

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
})
