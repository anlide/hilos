// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  bindPopupDismiss,
  revealPickerPanel,
} from '../../src/dom/popupEffects.js'

let unbind: (() => void) | undefined

afterEach(() => {
  unbind?.()
  unbind = undefined
  vi.restoreAllMocks()
  document.body.replaceChildren()
})

describe('bindPopupDismiss', () => {
  it.each(['pointerdown', 'focusin'])(
    'ignores %s on the root and its descendants',
    (type) => {
      const root = document.createElement('div')
      const child = document.createElement('button')
      const text = document.createTextNode('Choose day')
      child.append(text)
      root.append(child)
      document.body.append(root)
      const onDismiss = vi.fn()
      unbind = bindPopupDismiss(root, onDismiss)

      for (const target of [root, child, text]) {
        target.dispatchEvent(new Event(type, { bubbles: true }))
      }

      expect(onDismiss).not.toHaveBeenCalled()
    },
  )

  it.each(['pointerdown', 'focusin'])(
    'dismisses on an outside %s until unbound',
    (type) => {
      const root = document.createElement('div')
      const outside = document.createElement('button')
      document.body.append(root, outside)
      const onDismiss = vi.fn()
      unbind = bindPopupDismiss(root, onDismiss)

      outside.dispatchEvent(new Event(type, { bubbles: true }))
      expect(onDismiss).toHaveBeenCalledTimes(1)

      unbind()
      outside.dispatchEvent(new Event(type, { bubbles: true }))
      expect(onDismiss).toHaveBeenCalledTimes(1)
    },
  )

  it('observes a pointer press before an outside target stops propagation', () => {
    const root = document.createElement('div')
    const outside = document.createElement('button')
    document.body.append(root, outside)
    const calls: string[] = []
    outside.addEventListener('pointerdown', (event) => {
      calls.push('target')
      event.stopPropagation()
    })
    unbind = bindPopupDismiss(root, () => calls.push('dismiss'))

    outside.dispatchEvent(new Event('pointerdown', { bubbles: true }))

    expect(calls).toEqual(['dismiss', 'target'])
  })

  it.each(['pointerdown', 'focusin'])(
    'listens for %s only in the root owning document',
    (type) => {
      const other = document.implementation.createHTMLDocument()
      const root = other.createElement('div')
      other.body.append(root)
      const onDismiss = vi.fn()
      unbind = bindPopupDismiss(root, onDismiss)

      document.body.dispatchEvent(new Event(type, { bubbles: true }))
      root.dispatchEvent(new Event(type, { bubbles: true }))
      expect(onDismiss).not.toHaveBeenCalled()

      other.body.dispatchEvent(new Event(type, { bubbles: true }))
      expect(onDismiss).toHaveBeenCalledTimes(1)
    },
  )
})

describe('revealPickerPanel', () => {
  it('scrolls before focusing the active grid day without another scroll', () => {
    const panel = document.createElement('div')
    const time = document.createElement('input')
    time.setAttribute('role', 'spinbutton')
    const inactive = document.createElement('button')
    inactive.setAttribute('role', 'gridcell')
    inactive.tabIndex = -1
    const active = document.createElement('button')
    active.setAttribute('role', 'gridcell')
    active.tabIndex = 0
    panel.append(time, inactive, active)
    document.body.append(panel)
    const scroll = vi.spyOn(panel, 'scrollIntoView')
    const focus = vi.spyOn(active, 'focus')
    const inactiveFocus = vi.spyOn(inactive, 'focus')
    const timeFocus = vi.spyOn(time, 'focus')

    revealPickerPanel(panel)

    expect(scroll).toHaveBeenCalledExactlyOnceWith({ block: 'nearest' })
    expect(focus).toHaveBeenCalledExactlyOnceWith({ preventScroll: true })
    expect(scroll.mock.invocationCallOrder[0]).toBeLessThan(
      focus.mock.invocationCallOrder[0],
    )
    expect(document.activeElement).toBe(active)
    expect(inactiveFocus).not.toHaveBeenCalled()
    expect(timeFocus).not.toHaveBeenCalled()
  })

  it('focuses the first time part when no calendar grid is present', () => {
    const panel = document.createElement('div')
    const unrelated = document.createElement('input')
    const hour = document.createElement('input')
    hour.setAttribute('role', 'spinbutton')
    const minute = document.createElement('input')
    minute.setAttribute('role', 'spinbutton')
    panel.append(unrelated, hour, minute)
    document.body.append(panel)
    const scroll = vi.spyOn(panel, 'scrollIntoView')
    const focus = vi.spyOn(hour, 'focus')
    const unrelatedFocus = vi.spyOn(unrelated, 'focus')
    const minuteFocus = vi.spyOn(minute, 'focus')

    revealPickerPanel(panel)

    expect(scroll).toHaveBeenCalledExactlyOnceWith({ block: 'nearest' })
    expect(focus).toHaveBeenCalledExactlyOnceWith({ preventScroll: true })
    expect(document.activeElement).toBe(hour)
    expect(unrelatedFocus).not.toHaveBeenCalled()
    expect(minuteFocus).not.toHaveBeenCalled()
  })

  it('focuses the dialog when its time parts are disabled', () => {
    const outside = document.createElement('button')
    const panel = document.createElement('div')
    panel.setAttribute('role', 'dialog')
    panel.tabIndex = -1
    const hour = document.createElement('input')
    hour.setAttribute('role', 'spinbutton')
    hour.disabled = true
    const minute = document.createElement('input')
    minute.setAttribute('role', 'spinbutton')
    minute.disabled = true
    panel.append(hour, minute)
    document.body.append(outside, panel)
    outside.focus()
    const focus = vi.spyOn(panel, 'focus')
    const hourFocus = vi.spyOn(hour, 'focus')
    const minuteFocus = vi.spyOn(minute, 'focus')

    revealPickerPanel(panel)

    expect(document.activeElement).toBe(panel)
    expect(focus).toHaveBeenCalledExactlyOnceWith({ preventScroll: true })
    expect(hourFocus).not.toHaveBeenCalled()
    expect(minuteFocus).not.toHaveBeenCalled()
  })

  it('reveals a panel without a focus target without moving focus', () => {
    const outside = document.createElement('button')
    const panel = document.createElement('div')
    document.body.append(outside, panel)
    outside.focus()
    const scroll = vi.spyOn(panel, 'scrollIntoView')

    expect(() => revealPickerPanel(panel)).not.toThrow()

    expect(scroll).toHaveBeenCalledExactlyOnceWith({ block: 'nearest' })
    expect(document.activeElement).toBe(outside)
  })
})
