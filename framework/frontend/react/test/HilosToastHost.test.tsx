import { createHilosToastStore } from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosToastHost } from '../src/HilosToastHost.js'

// The host is the only place the hold events, the measurement reports and the
// live-region wiring are written down, so this covers them once for the three
// SDKs — the store's own behavior (lifetimes, holds, the height cap) is a core
// unit test.
function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

/**
 * Switch the tab away or back, the way the browser reports it.
 *
 * @param hidden Whether the document is now hidden.
 */
function switchTab(hidden: boolean): void {
  Object.defineProperty(document, 'hidden', {
    configurable: true,
    get: () => hidden,
  })
  fireEvent(document, new Event('visibilitychange'))
}

describe('HilosToastHost', () => {
  afterEach(() => {
    cleanup()
    switchTab(false)
  })

  it('reports the height of every card it drew, so the store can admit it', () => {
    const store = createHilosToastStore()
    store.push('Backup created.', { severity: 'success' })
    render(<HilosToastHost store={store} />)

    expect(store.toasts.get()[0].measured).toBe(true)
  })

  it('holds the countdown while the tab is not the one being looked at', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      switchTab(true)
      act(() => {
        vi.advanceTimersByTime(60_000)
      })
      expect(byId(container, 'hilos-toast-success')).not.toBeNull()

      switchTab(false)
      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('stops the countdown once it is no longer there to watch the stack', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { unmount } = render(<HilosToastHost store={store} />)

      unmount()
      act(() => {
        vi.advanceTimersByTime(60_000)
      })

      expect(store.toasts.get()).toHaveLength(1)
    } finally {
      vi.useRealTimers()
    }
  })

  it('holds the countdown while the cursor rests on the stack', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)
      const stack = byId(container, 'hilos-toasts') as HTMLElement

      // mouseover fires again for every element the cursor crosses inside the
      // stack, so the host takes at most one hold and a single leave gives it
      // back — otherwise the surplus would freeze the stack for good.
      fireEvent.mouseOver(stack)
      fireEvent.mouseOver(stack)
      act(() => {
        vi.advanceTimersByTime(60_000)
      })
      expect(byId(container, 'hilos-toast-success')).not.toBeNull()

      fireEvent.mouseLeave(stack)
      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('interrupts a screen reader for a failure and waits its turn for a success', () => {
    const store = createHilosToastStore()
    store.push('Backup failed.', { severity: 'error' })
    store.push('Backup created.', { severity: 'success' })
    const { container } = render(<HilosToastHost store={store} />)

    const failure = byId(container, 'hilos-toast-error') as HTMLElement
    expect(failure.getAttribute('role')).toBe('alert')
    expect(failure.getAttribute('aria-live')).toBe('assertive')

    const success = byId(container, 'hilos-toast-success') as HTMLElement
    expect(success.getAttribute('role')).toBe('status')
    expect(success.getAttribute('aria-live')).toBe('polite')
  })

  it('gives back the cursor hold when the toast under it closes itself', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup failed.', { severity: 'error' })
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      // The cursor rests on the stack and the notice under it takes itself out of
      // the DOM: the browser reports no mouseleave for it and never makes one up,
      // so unless the host gives the hold back by hand the stack stays frozen for
      // good. What is still on screen keeps its own hold, re-taken from the
      // mouseover the browser does send for the notice sliding underneath.
      fireEvent.mouseOver(byId(container, 'hilos-toasts') as HTMLElement)
      fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(store.toasts.get()).toEqual([])
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives back the focus hold when a close button removes itself', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup failed.', { severity: 'error' })
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      // Keyboard focus reaches a close button, and then that button takes itself
      // out of the DOM: the browser reports no focusout for it, so unless the host
      // gives the hold back by hand the stack stays frozen for good.
      fireEvent.focusIn(byId(container, 'hilos-toasts') as HTMLElement)
      fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(store.toasts.get()).toEqual([])
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('closes a notice from its close button', () => {
    const store = createHilosToastStore()
    store.push('Backup created.', { severity: 'success' })
    const { container } = render(<HilosToastHost store={store} />)

    fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

    expect(byId(container, 'hilos-toast-success')).toBeNull()
    expect(store.toasts.get()).toEqual([])
  })
})
