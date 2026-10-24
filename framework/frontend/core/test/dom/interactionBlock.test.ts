// @vitest-environment jsdom
// Native fieldset inheritance is supplied by jsdom, never simulated here.
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
} from '../../src/connection/interactionBlock.js'
import { bindInteractionBlock } from '../../src/dom/interactionBlock.js'
import { focusableElements, FocusTrap } from '../../src/dom/focusTrap.js'
import { createSignal } from '../../src/state/signal.js'

const disposers: Array<() => void> = []

afterEach(() => {
  for (const dispose of disposers.splice(0).reverse()) dispose()
  document.body.innerHTML = ''
})

/**
 * Mount real region markup and its permanent fieldset.
 * @param html The region's interactive and reading content.
 * @param initial Whether the region starts blocked.
 * @param parent The mount's document or containing region.
 */
function mount(
  html = '<input data-autofocus value="draft"><button>Save</button>',
  initial = false,
  parent: HTMLElement = document.body,
) {
  const root = parent.ownerDocument.createElement('main')
  root.tabIndex = -1
  root.innerHTML = `<fieldset>${html}</fieldset>`
  parent.append(root)
  const fieldset = root.querySelector('fieldset')!
  const blocked = createSignal(initial)
  const dispose = bindInteractionBlock(root, {
    fieldset,
    blocked,
    descriptionId: 'connection-reason',
  })
  disposers.push(dispose)

  return { root, fieldset, blocked, dispose }
}

/**
 * Dispatch a cancelable, bubbling gesture through the actual capture listeners.
 * @param target The gesture's original target.
 * @param type The DOM event name.
 * @param init Optional keyboard or transfer data.
 */
function gesture(
  target: HTMLElement,
  type: string,
  init: KeyboardEventInit = {},
): Event {
  const event = type.startsWith('key')
    ? new KeyboardEvent(type, { bubbles: true, cancelable: true, ...init })
    : new Event(type, { bubbles: true, cancelable: true })
  target.dispatchEvent(event)

  return event
}

/** Deliver local mutation records, including the observer's effect refresh. */
async function mutations(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
}

describe('bindInteractionBlock', () => {
  it('applies the snapshot and toggles the real fieldset without copying child disabled', () => {
    const { root, fieldset, blocked } = mount(
      '<input><select><option>A</option></select><textarea>Draft</textarea><button disabled>Own</button>',
      true,
    )
    const input = root.querySelector('input')!
    const button = root.querySelector('button')!
    expect(fieldset.disabled).toBe(true)
    expect(input.disabled).toBe(false)
    expect(input.matches(':disabled')).toBe(true)
    expect(root.querySelector('select')!.matches(':disabled')).toBe(true)
    expect(root.querySelector('textarea')!.matches(':disabled')).toBe(true)
    expect(root.hasAttribute(HILOS_INTERACTION_BLOCKED_ATTR)).toBe(true)
    expect(root.getAttribute('aria-describedby')).toBe('connection-reason')
    blocked.set(false)
    expect(input.matches(':disabled')).toBe(false)
    expect(button.disabled).toBe(true)
    expect(root.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(true)
    expect(root.hasAttribute(HILOS_INTERACTION_BLOCKED_ATTR)).toBe(false)
    expect(root.hasAttribute('aria-describedby')).toBe(false)
  })

  it.each(['click', 'auxclick', 'beforeinput', 'paste', 'drop', 'submit'])(
    'stops %s in capture before any target or ancestor handler',
    (type) => {
      const { root } = mount('<form><input><button>Save</button></form>', true)
      const target = root.querySelector(type === 'submit' ? 'form' : 'input')!
      const targetHandler = vi.fn()
      const ancestorHandler = vi.fn()
      target.addEventListener(type, targetHandler)
      root.addEventListener(type, ancestorHandler, true)
      expect(gesture(target, type).defaultPrevented).toBe(true)
      expect(targetHandler).not.toHaveBeenCalled()
      expect(ancestorHandler).not.toHaveBeenCalled()
    },
  )

  it('blocks requestSubmit even though it bypasses a button click', () => {
    const { root, blocked } = mount('<form><button>Save</button></form>')
    const form = root.querySelector('form')!
    const sent = vi.fn((event: Event) => event.preventDefault())
    form.addEventListener('submit', sent)
    blocked.set(true)
    form.requestSubmit()
    expect(sent).not.toHaveBeenCalled()
    blocked.set(false)
    form.requestSubmit()
    expect(sent).toHaveBeenCalledOnce()
  })

  it.each(['Enter', ' '])(
    'blocks %s activation of a link, summary and role, on every key phase',
    (key) => {
      const { root } = mount(
        '<a href="/next"><span>Next</span></a><details><summary>More</summary></details><div role="switch" tabindex="0">Switch</div>',
        true,
      )
      for (const target of root.querySelectorAll<HTMLElement>(
        'a span, summary, [role]',
      )) {
        for (const type of ['keydown', 'keypress', 'keyup']) {
          const action = vi.fn()
          target.addEventListener(type, action)
          expect(gesture(target, type, { key }).defaultPrevented).toBe(true)
          expect(action).not.toHaveBeenCalled()
        }
      }
    },
  )

  it('reads the flag at the gesture, before any observer or signal callback', () => {
    let live = false
    const { root, fieldset, dispose } = mount('<a href="/next">Next</a>')
    dispose()
    disposers.push(
      bindInteractionBlock(root, {
        fieldset,
        blocked: { get: () => live },
        descriptionId: 'reason',
      }),
    )
    live = true
    const link = root.querySelector('a')!
    const action = vi.fn()
    link.addEventListener('click', action)
    expect(gesture(link, 'click').defaultPrevented).toBe(true)
    expect(action).not.toHaveBeenCalled()
    live = false
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
    expect(action).toHaveBeenCalledOnce()
  })

  it('protects new links, roles and editable descendants before observer delivery', async () => {
    const { root, fieldset } = mount('', true)
    fieldset.innerHTML =
      '<a href="/next">Next</a><div role="button" tabindex="0">Action</div><div contenteditable="true"><span>Draft</span></div>'
    const link = root.querySelector('a')!
    const role = root.querySelector<HTMLElement>('[role]')!
    const editable = root.querySelector<HTMLElement>('[contenteditable]')!
    expect(gesture(link, 'click').defaultPrevented).toBe(true)
    expect(gesture(role, 'keydown', { key: 'Enter' }).defaultPrevented).toBe(
      true,
    )
    expect(
      gesture(editable.querySelector('span')!, 'beforeinput').defaultPrevented,
    ).toBe(true)
    await mutations()
    for (const control of [link, role, editable]) {
      expect(control.getAttribute('aria-disabled')).toBe('true')
      expect(control.getAttribute('aria-describedby')).toBe('connection-reason')
      expect(control.tabIndex).toBe(-1)
      expect(control.classList.contains('disabled')).toBe(true)
    }
    expect(editable.getAttribute('contenteditable')).toBe('false')
    expect(focusableElements(root)).toEqual([])
  })

  it('follows changed href, role and contenteditable and releases removed controls', async () => {
    const { fieldset, blocked } = mount('<div id="changing">Text</div>', true)
    const changing = fieldset.querySelector<HTMLElement>('div')!
    changing.setAttribute('role', 'button')
    expect(gesture(changing, 'click').defaultPrevented).toBe(true)
    await mutations()
    expect(changing.getAttribute('aria-disabled')).toBe('true')
    changing.removeAttribute('role')
    await mutations()
    expect(changing.hasAttribute('aria-disabled')).toBe(false)
    changing.setAttribute('contenteditable', '')
    await mutations()
    expect(changing.getAttribute('contenteditable')).toBe('false')
    changing.remove()
    await mutations()
    expect(changing.getAttribute('contenteditable')).toBe('')
    expect(changing.hasAttribute('class')).toBe(false)
    const link = document.createElement('a')
    fieldset.append(link)
    link.href = '/next'
    await mutations()
    expect(link.getAttribute('aria-disabled')).toBe('true')
    link.removeAttribute('href')
    await mutations()
    expect(link.hasAttribute('aria-disabled')).toBe(false)
    blocked.set(false)
  })

  it('preserves live owner attributes and tokens through unlock, including pending mutations', async () => {
    const { root, fieldset, blocked } = mount(
      '<a href="/next" tabindex="2" aria-disabled="false" aria-describedby="own" class="btn">Next</a><div contenteditable="true">Draft</div><input disabled>',
      true,
    )
    const link = root.querySelector('a')!
    const editable = root.querySelector<HTMLElement>('[contenteditable]')!
    const input = root.querySelector('input')!
    link.classList.add('active')
    link.setAttribute('aria-describedby', 'own connection-reason updated')
    link.setAttribute('tabindex', '5')
    link.setAttribute('aria-disabled', 'true')
    editable.setAttribute('contenteditable', 'plaintext-only')
    input.disabled = false
    fieldset.disabled = true
    await mutations()
    expect(link.tabIndex).toBe(-1)
    expect(editable.getAttribute('contenteditable')).toBe('false')
    expect(link.getAttribute('aria-describedby')).toBe(
      'own updated connection-reason',
    )
    link.classList.remove('btn')
    link.setAttribute('aria-describedby', 'last')
    blocked.set(false)
    expect(link.className).toBe('active')
    expect(link.getAttribute('aria-describedby')).toBe('last')
    expect(link.getAttribute('aria-disabled')).toBe('true')
    expect(link.tabIndex).toBe(5)
    expect(editable.getAttribute('contenteditable')).toBe('plaintext-only')
    expect(input.disabled).toBe(false)
    expect(fieldset.disabled).toBe(true)
  })

  it('retains preexisting disabled styles and reasons on cleanup', () => {
    const { root, dispose } = mount(
      '<a href="/next" class="disabled own" aria-disabled="true" aria-describedby="connection-reason own" tabindex="-1">Next</a>',
      true,
    )
    const link = root.querySelector('a')!
    dispose()
    expect(link.className).toBe('disabled own')
    expect(link.getAttribute('aria-disabled')).toBe('true')
    expect(link.getAttribute('aria-describedby')).toBe('connection-reason own')
    expect(link.tabIndex).toBe(-1)
  })

  it('keeps the same draft nodes, value, selection, editable text and scroll', () => {
    const { root, fieldset, blocked } = mount(
      '<textarea>My draft</textarea><div contenteditable="true">Selected text</div>',
    )
    const textarea = root.querySelector('textarea')!
    const editable = root.querySelector<HTMLElement>('[contenteditable]')!
    textarea.setSelectionRange(2, 6)
    const selection = document.getSelection()!
    const range = document.createRange()
    range.selectNodeContents(editable)
    selection.addRange(range)
    fieldset.scrollTop = 83
    root.scrollLeft = 17
    blocked.set(true)
    blocked.set(false)
    expect(root.querySelector('textarea')).toBe(textarea)
    expect(textarea.value).toBe('My draft')
    expect([textarea.selectionStart, textarea.selectionEnd]).toEqual([2, 6])
    expect(selection.toString()).toBe('Selected text')
    expect(editable.textContent).toBe('Selected text')
    expect(fieldset.scrollTop).toBe(83)
    expect(root.scrollLeft).toBe(17)
  })

  it('allows reading, selection, browser copy, scrolling and Tab in both directions', () => {
    const { root } = mount(
      '<p>Readable text</p><div contenteditable="true">Copy me</div>',
      true,
    )
    const text = root.querySelector('p')!
    const editable = root.querySelector<HTMLElement>('[contenteditable]')!
    for (const type of [
      'click',
      'auxclick',
      'beforeinput',
      'paste',
      'drop',
      'wheel',
      'scroll',
      'selectstart',
      'copy',
    ]) {
      expect(gesture(text, type).defaultPrevented, type).toBe(false)
    }
    for (const key of ['Tab', 'ArrowDown', 'Home', 'End', 'PageDown', ' ']) {
      expect(gesture(text, 'keydown', { key }).defaultPrevented, key).toBe(
        false,
      )
    }
    expect(
      gesture(root, 'keydown', { key: 'Tab', shiftKey: true }).defaultPrevented,
    ).toBe(false)
    for (const modifier of [{ ctrlKey: true }, { metaKey: true }]) {
      expect(
        gesture(editable, 'keydown', { key: 'c', ...modifier })
          .defaultPrevented,
      ).toBe(false)
    }
    expect(gesture(editable, 'copy').defaultPrevented).toBe(false)
  })

  it('blocks upload drops on a drop zone without disabling ordinary text drops', () => {
    const { root } = mount('<div id="upload">Upload</div><p>Read</p>', true)
    const upload = root.querySelector<HTMLElement>('#upload')!
    const uploading = vi.fn()
    upload.ondrop = uploading
    expect(gesture(upload, 'drop').defaultPrevented).toBe(true)
    expect(uploading).not.toHaveBeenCalled()
    const plain = root.querySelector('p')!
    const fileDrop = new Event('drop', { bubbles: true, cancelable: true })
    Object.defineProperty(fileDrop, 'dataTransfer', {
      value: { types: ['Files'] },
    })
    plain.addEventListener('drop', uploading)
    plain.dispatchEvent(fileDrop)
    expect(fileDrop.defaultPrevented).toBe(true)
    expect(uploading).not.toHaveBeenCalled()
  })

  it('moves focused interaction to root and returns to the available previous element', () => {
    const { root, blocked } = mount()
    const input = root.querySelector('input')!
    input.focus()
    blocked.set(true)
    expect(document.activeElement).toBe(root)
    const trap = new FocusTrap()
    const tab = new KeyboardEvent('keydown', { key: 'Tab', cancelable: true })
    trap.handleTab(root, tab)
    expect(tab.defaultPrevented).toBe(true)
    blocked.set(false)
    expect(document.activeElement).toBe(input)
  })

  it('uses initial focus when the previous control became unavailable', () => {
    const { root, blocked } = mount(
      '<input data-autofocus><button>Action</button>',
    )
    const button = root.querySelector('button')!
    button.focus()
    blocked.set(true)
    button.disabled = true
    blocked.set(false)
    expect(document.activeElement).toBe(root.querySelector('input'))
  })

  it('does not take focus from an explicit outside choice on unlock or cleanup', () => {
    const { root, blocked, dispose } = mount()
    const outside = document.createElement('button')
    document.body.append(outside)
    root.querySelector('input')!.focus()
    blocked.set(true)
    outside.focus()
    blocked.set(false)
    expect(document.activeElement).toBe(outside)
    blocked.set(true)
    dispose()
    expect(document.activeElement).toBe(outside)
  })

  it.each([false, true])(
    'gives nested dialog/confirm focus priority with inner subscription first=%s',
    (innerFirst) => {
      const main = mount('<input data-autofocus>')
      const dialog = mount('<input data-autofocus>', false, main.fieldset)
      const confirm = mount('<button>Discard</button>', false, dialog.fieldset)
      // Rebind against one signal in either subscriber order.
      main.dispose()
      dialog.dispose()
      confirm.dispose()
      const signal = createSignal(false)
      const regions = innerFirst
        ? [confirm, dialog, main]
        : [main, dialog, confirm]
      for (const region of regions)
        disposers.push(
          bindInteractionBlock(region.root, {
            fieldset: region.fieldset,
            blocked: signal,
            descriptionId: 'reason',
          }),
        )
      const action = confirm.root.querySelector('button')!
      action.focus()
      signal.set(true)
      expect(document.activeElement).toBe(confirm.root)
      expect(gesture(action, 'click').defaultPrevented).toBe(true)
      signal.set(false)
      expect(document.activeElement).toBe(action)
    },
  )

  it('hands effects to the nearest region and does not restore another binding over them', async () => {
    const main = mount(
      '<section tabindex="-1"><fieldset><a href="/next" class="btn">Next</a></fieldset></section>',
      true,
    )
    const dialog = main.root.querySelector('section')!
    const fieldset = dialog.querySelector('fieldset')!
    const link = dialog.querySelector('a')!
    const blocked = createSignal(true)
    const dispose = bindInteractionBlock(dialog, {
      fieldset,
      blocked,
      descriptionId: 'dialog-reason',
    })
    disposers.push(dispose)
    await mutations()
    expect(link.getAttribute('aria-describedby')).toBe('dialog-reason')
    main.blocked.set(false)
    expect(link.classList.contains('disabled')).toBe(true)
    expect(link.getAttribute('aria-disabled')).toBe('true')
    expect(gesture(link, 'click').defaultPrevented).toBe(true)
    blocked.set(false)
    expect(link.className).toBe('btn')
    expect(link.hasAttribute('aria-describedby')).toBe(false)
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
  })

  it('leaves an opted-out nested region to its own policy even under blocked main', async () => {
    const main = mount(
      '<section tabindex="-1"><fieldset><a href="/next">Next</a></fieldset></section>',
      true,
    )
    const dialog = main.root.querySelector('section')!
    const dispose = bindInteractionBlock(dialog, {
      fieldset: dialog.querySelector('fieldset')!,
      blocked: createSignal(false),
      descriptionId: 'inner',
    })
    disposers.push(dispose)
    const link = dialog.querySelector('a')!
    await mutations()
    const action = vi.fn()
    link.addEventListener('click', action)
    gesture(link, 'click')
    expect(action).toHaveBeenCalledOnce()
    expect(link.hasAttribute('aria-disabled')).toBe(false)
    link.focus()
    expect(document.activeElement).toBe(link)
  })

  it('cleans up once, stops observation and supports replacement on the same root', async () => {
    const { root, fieldset, blocked, dispose } = mount(
      '<a href="/next">Next</a>',
      true,
    )
    const link = root.querySelector('a')!
    const next = createSignal(false)
    const disposeNext = bindInteractionBlock(root, {
      fieldset,
      blocked: next,
      descriptionId: 'new',
    })
    disposers.push(disposeNext)
    dispose()
    expect(link.hasAttribute('aria-disabled')).toBe(false)
    next.set(true)
    expect(link.getAttribute('aria-describedby')).toBe('new')
    blocked.set(false)
    expect(link.getAttribute('aria-disabled')).toBe('true')
    disposeNext()
    disposeNext()
    link.className = 'owner'
    next.set(false)
    next.set(true)
    await mutations()
    expect(root.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
    expect(fieldset.disabled).toBe(false)
    expect(link.className).toBe('owner')
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
  })

  it('uses ownerDocument in a different browsing context', () => {
    const frame = document.createElement('iframe')
    document.body.append(frame)
    const other = frame.contentDocument!
    const { root, blocked } = mount('<input>', false, other.body)
    const input = root.querySelector('input')!
    input.focus()
    blocked.set(true)
    expect(other.activeElement).toBe(root)
    blocked.set(false)
    expect(other.activeElement).toBe(input)
  })
})

describe('live interaction ownership', () => {
  it.each([{ ctrlKey: true }, { metaKey: true }, { altKey: true }])(
    'blocks modified activation without swallowing browser copy: %s',
    (modifier) => {
      const { root } = mount('<a href="/next">Next</a>', true)
      const link = root.querySelector('a')!
      expect(
        gesture(link, 'keydown', { key: 'Enter', ...modifier })
          .defaultPrevented,
      ).toBe(true)
      expect(
        gesture(link, 'keyup', { key: ' ', ...modifier }).defaultPrevented,
      ).toBe(true)
      expect(
        gesture(link, 'keydown', { key: 'c', ...modifier }).defaultPrevented,
      ).toBe(false)
    },
  )

  it('blocks role value changes but preserves reading keys on plain text', () => {
    const { root } = mount(
      '<div role="slider" tabindex="0">Value</div><p>Read</p>',
      true,
    )
    const slider = root.querySelector<HTMLElement>('[role]')!
    const text = root.querySelector('p')!
    for (const key of [
      'ArrowUp',
      'ArrowDown',
      'Home',
      'End',
      'PageUp',
      'PageDown',
    ]) {
      expect(gesture(slider, 'keydown', { key }).defaultPrevented).toBe(true)
      expect(gesture(text, 'keydown', { key }).defaultPrevented).toBe(false)
    }
  })

  it('keeps focus on the region when a newly inserted role is focused before observer delivery', () => {
    const { root, fieldset, blocked } = mount('', true)
    const role = document.createElement('div')
    role.setAttribute('role', 'button')
    role.tabIndex = 0
    fieldset.append(role)
    role.focus()
    expect(document.activeElement).toBe(root)
    blocked.set(false)
    expect(document.activeElement).toBe(role)
  })

  it('restores from body only when this region displaced focus', () => {
    const { root, blocked } = mount()
    expect(document.activeElement).toBe(document.body)
    blocked.set(true)
    blocked.set(false)
    expect(document.activeElement).toBe(document.body)
    const input = root.querySelector('input')!
    input.focus()
    blocked.set(true)
    root.blur()
    expect(document.activeElement).toBe(document.body)
    blocked.set(false)
    expect(document.activeElement).toBe(input)
  })

  it('reads pending owner removals before cleanup and leaves native disabled updates alone', () => {
    const { root, fieldset, dispose } = mount(
      '<a href="/next" class="disabled" aria-disabled="true" tabindex="4" aria-describedby="owner">Next</a><div contenteditable="true">Text</div><input>',
      true,
    )
    const link = root.querySelector('a')!
    const editable = root.querySelector<HTMLElement>('[contenteditable]')!
    const input = root.querySelector('input')!
    link.removeAttribute('aria-disabled')
    link.removeAttribute('tabindex')
    link.removeAttribute('aria-describedby')
    link.classList.remove('disabled')
    editable.removeAttribute('contenteditable')
    input.disabled = true
    fieldset.disabled = false
    root.setAttribute('aria-describedby', 'new-root-reason')
    dispose()
    expect(link.hasAttribute('aria-disabled')).toBe(false)
    expect(link.hasAttribute('tabindex')).toBe(false)
    expect(link.hasAttribute('aria-describedby')).toBe(false)
    expect(link.classList.contains('disabled')).toBe(false)
    expect(editable.hasAttribute('contenteditable')).toBe(false)
    expect(input.disabled).toBe(true)
    expect(fieldset.disabled).toBe(false)
    expect(root.getAttribute('aria-describedby')).toBe('new-root-reason')
  })

  it('allows programmatic updates without remounting or replaying a blocked action', () => {
    const { root, blocked } = mount('<input><button>Action</button>', true)
    const input = root.querySelector('input')!
    const button = root.querySelector('button')!
    const action = vi.fn()
    button.addEventListener('click', action)
    gesture(button, 'click')
    input.value = 'Server update'
    blocked.set(false)
    expect(input.value).toBe('Server update')
    expect(action).not.toHaveBeenCalled()
    gesture(button, 'click')
    expect(action).toHaveBeenCalledOnce()
  })
})

describe('gesture targets before observer delivery', () => {
  it('blocks activation through SVG and noneditable descendants of an anchor', () => {
    const { root } = mount(
      '<a href="/next"><svg><path /></svg><span contenteditable="false">Label</span></a>',
      true,
    )
    const activated = vi.fn()
    root.querySelector('a')!.addEventListener('click', activated)
    const path = root.querySelector('path')!
    const event = new MouseEvent('click', { bubbles: true, cancelable: true })
    path.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
    expect(gesture(root.querySelector('span')!, 'click').defaultPrevented).toBe(
      true,
    )
    expect(activated).not.toHaveBeenCalled()
  })

  it('blocks editing when an existing control becomes editable before observation', async () => {
    const { root } = mount(
      '<a href="/next" contenteditable="true">Draft</a>',
      true,
    )
    const link = root.querySelector('a')!
    link.setAttribute('contenteditable', 'false')
    await mutations()
    link.setAttribute('contenteditable', 'true')
    expect(gesture(link, 'beforeinput').defaultPrevented).toBe(true)
    expect(gesture(link, 'paste').defaultPrevented).toBe(true)
  })
})

describe('cleanup focus', () => {
  it('returns to the exact previous control before releasing its region marker', () => {
    const { root, blocked, dispose } = mount(
      '<input data-autofocus><button>Previously focused</button>',
    )
    const button = root.querySelector('button')!
    button.focus()
    blocked.set(true)
    expect(document.activeElement).toBe(root)
    dispose()
    expect(document.activeElement).toBe(button)
    expect(root.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
  })
})

describe('reading focus', () => {
  it('leaves a focused reading passage in place through a connection cycle', () => {
    const { root, blocked } = mount(
      '<p tabindex="-1">Passage being read</p><input data-autofocus>',
    )
    const text = root.querySelector('p')!
    text.focus()
    blocked.set(true)
    expect(document.activeElement).toBe(text)
    blocked.set(false)
    expect(document.activeElement).toBe(text)
  })
})

describe('early delegated capture', () => {
  it('guards before an ancestor renderer sees capture, using the live flag and nearest region', () => {
    const renderer = document.createElement('section')
    document.body.append(renderer)
    const captured = vi.fn()
    const types = [
      'click',
      'auxclick',
      'keydown',
      'submit',
      'beforeinput',
      'paste',
      'drop',
    ]
    for (const type of types) renderer.addEventListener(type, captured, true)
    const main = mount(
      '<a href="#next">Next</a><form><input></form>',
      false,
      renderer,
    )
    const inner = mount('<a href="#inner">Inner</a>', false, main.fieldset)
    const sibling = document.createElement('a')
    sibling.href = '#shell'
    renderer.append(sibling)
    let live = true
    const dispose = bindInteractionBlock(main.root, {
      fieldset: main.fieldset,
      blocked: { get: () => live },
      descriptionId: 'main',
    })
    disposers.push(dispose)
    const link = main.root.querySelector('a')!
    const input = main.root.querySelector('input')!
    for (const type of types) {
      const target =
        type === 'submit'
          ? main.root.querySelector('form')!
          : type === 'click' || type === 'auxclick' || type === 'keydown'
            ? link
            : input
      expect(gesture(target, type, { key: 'Enter' }).defaultPrevented).toBe(
        true,
      )
      expect(captured).not.toHaveBeenCalled()
    }
    for (const target of [inner.root.querySelector('a')!, sibling]) {
      expect(gesture(target, 'click').defaultPrevented).toBe(false)
      expect(captured).toHaveBeenCalledOnce()
      captured.mockClear()
    }
    inner.blocked.set(true)
    expect(
      gesture(inner.root.querySelector('a')!, 'click').defaultPrevented,
    ).toBe(true)
    expect(captured).not.toHaveBeenCalled()
    live = false
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
    expect(captured).toHaveBeenCalledOnce()
    captured.mockClear()
    live = true
    const replacement = bindInteractionBlock(main.root, {
      fieldset: main.fieldset,
      blocked: createSignal(false),
      descriptionId: 'replacement',
    })
    disposers.push(replacement)
    dispose()
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
    expect(captured).toHaveBeenCalledOnce()
    captured.mockClear()
    replacement()
    expect(gesture(link, 'click').defaultPrevented).toBe(false)
    expect(captured).toHaveBeenCalledOnce()
  })

  it('attaches and removes early listeners only on the root ownerDocument', () => {
    const frame = document.createElement('iframe')
    document.body.append(frame)
    const other = frame.contentDocument!
    const captured = vi.fn()
    other.body.addEventListener('click', captured, true)
    const added = vi.spyOn(other, 'addEventListener')
    const removed = vi.spyOn(other, 'removeEventListener')
    const parentAdded = vi.spyOn(document, 'addEventListener')
    const region = mount('<a href="#other">Other</a>', true, other.body)
    const link = region.root.querySelector('a')!
    const view = other.defaultView!
    const click = () =>
      link.dispatchEvent(
        new view.MouseEvent('click', { bubbles: true, cancelable: true }),
      )
    expect(click()).toBe(false)
    expect(captured).not.toHaveBeenCalled()
    expect(parentAdded).not.toHaveBeenCalled()
    const listeners = added.mock.calls.filter(([type]) =>
      [
        'click',
        'auxclick',
        'keydown',
        'keyup',
        'keypress',
        'submit',
        'beforeinput',
        'paste',
        'drop',
      ].includes(type),
    )
    expect(listeners).toHaveLength(9)
    region.dispose()
    for (const [type, handler, capture] of listeners) {
      expect(capture).toBe(true)
      expect(removed).toHaveBeenCalledWith(type, handler, true)
    }
    expect(click()).toBe(true)
    expect(captured).toHaveBeenCalledOnce()
    added.mockRestore()
    removed.mockRestore()
    parentAdded.mockRestore()
  })
})
