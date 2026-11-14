// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  bindModalOpen,
  lockBodyScroll,
  unlockBodyScroll,
  type ScrollLockOwner,
} from '../../src/dom/scrollLock.js'

const owners: ScrollLockOwner[] = []
const subscriptions: Array<() => void> = []

afterEach(() => {
  for (const unbind of subscriptions.splice(0)) unbind()
  for (const owner of owners.splice(0)) unlockBodyScroll(owner)
  vi.restoreAllMocks()
  Reflect.deleteProperty(window, 'visualViewport')
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/**
 * Lock a document for an owner that the suite releases after the test.
 * @param doc The document to lock.
 * @param owner The owner taking the lock.
 */
function lock(doc: Document, owner: ScrollLockOwner = {}): ScrollLockOwner {
  owners.push(owner)
  lockBodyScroll(doc, owner)

  return owner
}

/**
 * The class sets of a document's root and body, in order.
 * @param doc The document read.
 */
function classSets(doc: Document): { root: string[]; body: string[] } {
  return {
    root: [...doc.documentElement.classList],
    body: [...doc.body.classList],
  }
}

/**
 * Mount page markup into the real document's body.
 * @param html The page content under the lock.
 */
function mount(html: string): void {
  document.body.innerHTML = html
}

/**
 * Find a mounted element by its test id.
 * @param id The element's `data-testid`.
 */
function element(id: string): HTMLElement {
  return document.querySelector<HTMLElement>(`[data-testid="${id}"]`)!
}

/**
 * Give a mounted scroll container 300px of room and a current position; its
 * `overflow-y: auto` comes from the computed style stub below.
 * @param id The container's `data-testid`.
 * @param scrollTop The container's current position.
 */
function scrollable(id: string, scrollTop: number): void {
  const container = element(id)
  container.setAttribute('data-scrollport', '')
  Object.defineProperty(container, 'scrollHeight', { value: 500 })
  Object.defineProperty(container, 'clientHeight', { value: 200 })
  Object.defineProperty(container, 'scrollTop', { value: scrollTop })
}

/**
 * Give the window a visual viewport at a pinch zoom scale; happy-dom has none.
 * The suite removes it after the test.
 * @param scale The visual viewport's scale.
 */
function zoomTo(scale: number): void {
  Object.defineProperty(window, 'visualViewport', {
    value: { scale },
    configurable: true,
  })
}

/**
 * Dispatch a cancelable touch event with one touch per point.
 * @param target The touched element.
 * @param type The touch event name.
 * @param points The touches' client coordinates.
 */
function touch(
  target: Element,
  type: 'touchstart' | 'touchmove',
  points: Array<[number, number]>,
): Event {
  const touches = points.map(
    ([clientX, clientY], identifier) =>
      new Touch({ identifier, target, clientX, clientY }),
  )
  const event = new TouchEvent(type, {
    bubbles: true,
    cancelable: true,
    composed: true,
    touches,
  })
  target.dispatchEvent(event)

  return event
}

/**
 * Start a one-finger drag and move it once.
 * @param target The touched element.
 * @param from The finger's start.
 * @param to The finger's next position.
 * @returns The move, whose default the guard may have prevented.
 */
function drag(
  target: Element,
  from: [number, number],
  to: [number, number],
): Event {
  touch(target, 'touchstart', [from])

  return touch(target, 'touchmove', [to])
}

describe('bindModalOpen', () => {
  it('reports false immediately for a free document', () => {
    const onChange = vi.fn()

    subscriptions.push(bindModalOpen(document, onChange))

    expect(onChange).toHaveBeenCalledExactlyOnceWith(false)
  })

  it('reports true immediately for a locked document', () => {
    lock(document)
    const onChange = vi.fn()

    subscriptions.push(bindModalOpen(document, onChange))

    expect(onChange).toHaveBeenCalledExactlyOnceWith(true)
  })

  it('reports only the first lock and the last unlock', () => {
    const onChange = vi.fn()
    subscriptions.push(bindModalOpen(document, onChange))
    onChange.mockClear()

    const first = lock(document)
    const second = lock(document)
    lock(document, first)

    expect(onChange.mock.calls).toEqual([[true]])

    unlockBodyScroll(first)

    expect(onChange.mock.calls).toEqual([[true]])

    unlockBodyScroll(second)

    expect(onChange.mock.calls).toEqual([[true], [false]])
  })

  it('does not report another document changing', () => {
    const onChange = vi.fn()
    subscriptions.push(bindModalOpen(document, onChange))
    onChange.mockClear()
    const other = document.implementation.createHTMLDocument()

    const owner = lock(other)
    unlockBodyScroll(owner)

    expect(onChange).not.toHaveBeenCalled()
  })

  it('stops reporting after unsubscribe while other subscribers still follow', () => {
    const onChange = vi.fn()
    const remaining = vi.fn()
    const unbind = bindModalOpen(document, onChange)
    subscriptions.push(unbind, bindModalOpen(document, remaining))
    onChange.mockClear()
    remaining.mockClear()

    unbind()
    const owner = lock(document)
    unlockBodyScroll(owner)

    expect(onChange).not.toHaveBeenCalled()
    expect(remaining.mock.calls).toEqual([[true], [false]])
  })
})

describe('scroll lock', () => {
  it('keeps one document locked until its last owner releases it', () => {
    const first = {}
    const second = {}
    lockBodyScroll(document, first)
    lockBodyScroll(document, second)

    unlockBodyScroll(first)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    unlockBodyScroll(second)
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('touches no class when an owner did not take a lock', () => {
    const owner = {}

    unlockBodyScroll(owner)
    expect(document.body.classList.contains('modal-open')).toBe(false)

    document.body.classList.add('modal-open')
    unlockBodyScroll(owner)
    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('keeps a repeated lock by one owner idempotent', () => {
    const owner = {}
    lockBodyScroll(document, owner)
    lockBodyScroll(document, owner)

    unlockBodyScroll(owner)

    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('tracks the owners of different documents separately', () => {
    const other = document.implementation.createHTMLDocument()
    const first = {}
    const second = {}
    lockBodyScroll(document, first)
    lockBodyScroll(other, second)

    unlockBodyScroll(first)
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(other.body.classList.contains('modal-open')).toBe(true)

    unlockBodyScroll(second)
    expect(other.body.classList.contains('modal-open')).toBe(false)
  })

  it('returns the root and body classes as they were after the last owner', () => {
    const doc = document.implementation.createHTMLDocument()
    doc.documentElement.className = 'h-100'
    doc.body.className = 'overflow-hidden page'
    const before = classSets(doc)
    const first = lock(doc)
    const second = lock(doc)

    unlockBodyScroll(first)
    unlockBodyScroll(second)

    expect(classSets(doc)).toEqual(before)
  })

  it('keeps the first snapshot when one owner locks again', () => {
    mount('<p data-testid="background">Page</p>')
    const before = classSets(document)
    const owner = lock(document)
    lock(document, owner)

    unlockBodyScroll(owner)

    expect(classSets(document)).toEqual(before)
    expect(
      drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
    ).toBe(false)
  })

  it('keeps guarding touch while a second owner holds the document', () => {
    mount('<p data-testid="background">Page</p>')
    const first = lock(document)
    lock(document)

    unlockBodyScroll(first)

    expect(
      drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
    ).toBe(true)
  })

  it('releases two documents independently', () => {
    mount('<p data-testid="background">Page</p>')
    const other = document.implementation.createHTMLDocument()
    const before = classSets(document)
    const otherBefore = classSets(other)
    const first = lock(document)
    const second = lock(other)

    unlockBodyScroll(first)
    expect(classSets(document)).toEqual(before)
    expect(
      drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
    ).toBe(false)
    expect(other.body.classList.contains('modal-open')).toBe(true)

    unlockBodyScroll(second)
    expect(classSets(other)).toEqual(otherBefore)
  })

  describe('touch guard', () => {
    beforeEach(() => {
      const computed = window.getComputedStyle.bind(window)
      vi.spyOn(window, 'getComputedStyle').mockImplementation((target) =>
        target.hasAttribute('data-scrollport')
          ? ({ overflowY: 'auto' } as CSSStyleDeclaration)
          : computed(target),
      )
      mount(`
        <p data-testid="background">Page</p>
        <input data-testid="range" type="range">
        <div data-testid="outer">
          <div data-testid="inner"><p data-testid="line">Body</p></div>
        </div>
      `)
    })

    it('cancels a vertical drag over a background that cannot scroll', () => {
      lock(document)

      expect(
        drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
      ).toBe(true)
    })

    it('lets a finger moving up scroll a container down from its middle', () => {
      scrollable('inner', 100)
      lock(document)

      expect(drag(element('line'), [10, 100], [10, 50]).defaultPrevented).toBe(
        false,
      )
    })

    it('lets a finger moving down scroll a container up from its middle', () => {
      scrollable('inner', 100)
      lock(document)

      expect(drag(element('line'), [10, 50], [10, 100]).defaultPrevented).toBe(
        false,
      )
    })

    it('cancels a drag out past the top edge of a container', () => {
      scrollable('inner', 0)
      lock(document)

      expect(drag(element('line'), [10, 50], [10, 100]).defaultPrevented).toBe(
        true,
      )
    })

    it('cancels a drag out past the bottom edge of a container', () => {
      scrollable('inner', 300)
      lock(document)

      expect(drag(element('line'), [10, 100], [10, 50]).defaultPrevented).toBe(
        true,
      )
    })

    it('cancels a drag over a background that cannot scroll only on an unzoomed page', () => {
      zoomTo(1)
      lock(document)

      expect(
        drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
      ).toBe(true)

      zoomTo(2)

      expect(
        drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
      ).toBe(false)
    })

    it('judges the drag again from its fresh position after the zoom ends', () => {
      lock(document)
      const background = element('background')

      zoomTo(2)
      touch(background, 'touchstart', [[10, 100]])
      expect(touch(background, 'touchmove', [[90, 100]]).defaultPrevented).toBe(
        false,
      )

      zoomTo(1)

      expect(touch(background, 'touchmove', [[90, 90]]).defaultPrevented).toBe(
        true,
      )
    })

    it('cancels a finger moving up from a container less than a pixel above its bottom', () => {
      scrollable('inner', 299.5)
      lock(document)

      expect(drag(element('line'), [10, 100], [10, 50]).defaultPrevented).toBe(
        true,
      )
    })

    it('cancels a finger moving down from a container less than a pixel below its top', () => {
      scrollable('inner', 0.5)
      lock(document)

      expect(drag(element('line'), [10, 50], [10, 100]).defaultPrevented).toBe(
        true,
      )
    })

    it('still lets a container a full pixel above its bottom take a finger moving up', () => {
      scrollable('inner', 299)
      lock(document)

      expect(drag(element('line'), [10, 100], [10, 50]).defaultPrevented).toBe(
        false,
      )
    })

    it('still lets a container a full pixel below its top take a finger moving down', () => {
      scrollable('inner', 1)
      lock(document)

      expect(drag(element('line'), [10, 50], [10, 100]).defaultPrevented).toBe(
        false,
      )
    })

    it('hands a drag from a container at its edge to a scrollable ancestor', () => {
      scrollable('outer', 100)
      scrollable('inner', 0)
      lock(document)

      expect(drag(element('line'), [10, 50], [10, 100]).defaultPrevented).toBe(
        false,
      )
    })

    it('leaves a two-finger pinch alone', () => {
      const background = element('background')
      lock(document)

      touch(background, 'touchstart', [
        [10, 100],
        [60, 100],
      ])
      const pinch = touch(background, 'touchmove', [
        [10, 60],
        [60, 140],
      ])

      expect(pinch.defaultPrevented).toBe(false)
    })

    it('leaves a horizontal range drag alone and frees touch after the last unlock', () => {
      const owner = lock(document)

      expect(
        drag(element('range'), [10, 100], [80, 102]).defaultPrevented,
      ).toBe(false)
      expect(
        drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
      ).toBe(true)

      unlockBodyScroll(owner)
      expect(
        drag(element('background'), [10, 100], [10, 50]).defaultPrevented,
      ).toBe(false)
    })
  })
})
